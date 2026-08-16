<?php

namespace Lightspeed\Protocol;

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Diagnostics\DiagnosticSockets;
use Lightspeed\Presence\ConnectionId;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Connections\ConnectionClosed;
use Lightspeed\Connections\ConnectionClosedDispatcher;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * Take a closed connection out of every list it is in, and say it left.
 *
 * The mirror of Handshake: protocol eligibility, grants, subscriptions,
 * presence membership and the cross-node registry entry all go, and the
 * channels that were watching a presence member are told it is gone, and so is
 * the application, through Connections\ConnectionClosedDispatcher, but only for
 * a connection that held a channel or a grant: see closeConnection().
 *
 * This is best-effort by nature rather than by choice. A worker that is
 * SIGKILLed never runs it at all, which is why Presence\PresenceSweeper exists
 * to reconcile what it could not: nothing else in the package treats a close
 * having happened as a fact.
 */
class Teardown
{
    public function __construct(
        private readonly ChannelManager $channels,
        private readonly ConnectionGrants $connectionGrants,
        private readonly ConnectionRegistry $connectionRegistry,
        private readonly PresenceStore $presenceStore,
        private readonly DiagnosticSockets $diagnosticSockets,
        private readonly ConnectionId $connectionId,
        private readonly Delivery $delivery,
        private readonly RuntimeLogger $runtimeLogger,
        private readonly ConnectionClosedDispatcher $connectionClosed,
    ) {
    }

    public function closeConnection(SwooleServer $server, int $fd): void
    {
        // Asked first because the answer decides whether anything below has to
        // be remembered at all: an application that registers no handler pays
        // one config lookup and nothing else.
        $notify = $this->connectionClosed->hasHandlers();

        // A READ TAKEN BEFORE A WRITE THAT DESTROYS WHAT IT READS. The grants
        // are what the connection proved it was allowed to do, and
        // forgetConnection() below is what drops them; after that line there is
        // nowhere left to learn them from. Nothing about the order of the side
        // effects changes: this reads, and reads only.
        $grants = $notify ? $this->connectionGrants->grantsFor($fd) : [];

        // THE SECOND READ THAT HAS TO HAPPEN BEFORE ITS OWN FORGET, and for a
        // reason the channel list cannot answer for. Diagnostics\DiagnosticProtocol
        // attaches to channels through ChannelManager directly, with no
        // subscribe ladder, no channel authorization and no grant, so a
        // diagnostic socket's close arrives here looking exactly like an
        // authorized one: a non-empty channel list. Letting it through would
        // make "your handler only fires for connections that passed channel
        // authorization" false whenever an operator enables diagnostics. Reads
        // only, and the forget below is unmoved.
        $wasDiagnostic = $this->diagnosticSockets->allows($fd);

        $this->diagnosticSockets->forget($fd);
        $this->connectionGrants->forgetConnection($fd);

        $disconnect = $this->channels->disconnect($fd);
        $presenceConnectionId = $this->connectionId->presenceConnectionId($fd, $disconnect['socket_id'] ?? null);

        $presenceLeaves = [];

        foreach ($disconnect['presence_leaves'] as $leave) {
            $presenceLeave = $this->presenceStore->leave($leave['channel'], $presenceConnectionId);

            if ($presenceLeave['broadcast_member_removed']) {
                $this->delivery->broadcastPusherEvent(
                    $leave['channel'],
                    'pusher_internal:member_removed',
                    [
                        'user_id' => (string) ($leave['presence_member']['user_id'] ?? ''),
                    ],
                    $fd,
                );

                if ($notify) {
                    // The same departures the channel was told about, and only
                    // those: a member with another connection still on the
                    // channel has not left it.
                    $presenceLeaves[] = [
                        'channel' => $leave['channel'],
                        'user_id' => isset($leave['presence_member']['user_id'])
                            ? (string) $leave['presence_member']['user_id']
                            : null,
                        'user_info' => is_array($leave['presence_member']['user_info'] ?? null)
                            ? $leave['presence_member']['user_info']
                            : null,
                    ];
                }
            }
        }

        $this->connectionRegistry->forget($disconnect['socket_id'] ?? null);

        $channels = $disconnect['channels'];
        $wasAConnection = ($disconnect['socket_id'] ?? null) !== null || $channels !== [];

        if ($wasAConnection) {
            $this->runtimeLogger->logWebsocket('close', [
                'fd' => $fd,
                'socket' => $disconnect['socket_id'] ?? null,
                'channels' => $channels === [] ? null : $channels,
            ]);
        }

        // LAST, so a handler sees a connection that has already left everything
        // it was in rather than one halfway out, and so no application code can
        // come between two steps of the teardown.
        //
        // AUTHORIZED CONNECTIONS ONLY, which is a narrower gate than the log
        // line above and deliberately so. Anyone holding the public app key can
        // open a socket, complete the handshake, join nothing and hang up; that
        // is a connection by the log's standard, and reaching application code
        // with it makes a loop of open-and-close a lever on the event loop that
        // serves every other connection. A channel subscription or a grant is
        // the point at which the APPLICATION agreed to this connection, so it is
        // the point at which the application hears about it. A diagnostic
        // socket never passed that agreement, whatever channels it holds.
        if ($notify && !$wasDiagnostic && ($channels !== [] || $grants !== [])) {
            $this->connectionClosed->dispatch(ConnectionClosed::closed(
                socketId: $disconnect['socket_id'] ?? null,
                channels: $channels,
                presenceLeaves: $presenceLeaves,
                tags: $this->tagsOf($grants),
                authPayloads: $this->authPayloadsOf($grants),
            ));
        }
    }

    /**
     * Every tag this connection carried, deduplicated, in a sorted order.
     *
     * SORTED BECAUSE THE ALTERNATIVE WAS THE CLIENT'S TO CHOOSE. The order a
     * connection "earned" its tags in is the grant table's insertion order, and
     * ConnectionGrants::mint() forgets and reassigns, so a re-subscribe moves a
     * channel to the end of it: the same reorder authPayloadsOf() below is
     * keyed by channel to escape. A tag is only ever compared for equality, so
     * no application needs an order here; what it needs is the same list for
     * the same connection however the client shuffled its subscriptions.
     *
     * @param  array<array-key, \Lightspeed\Auth\Grant>  $grants
     * @return list<string>
     */
    private function tagsOf(array $grants): array
    {
        $tags = [];

        foreach ($grants as $grant) {
            foreach ($grant->tags as $tag) {
                // Keyed to deduplicate, and VALUED with the tag itself rather
                // than with a marker: PHP stores the array key "7" as the
                // integer 7, so a numeric tag read back off the keys would
                // reach the application as a different type from the one the
                // application signed.
                $tags[$tag] = $tag;
            }
        }

        // sort() discards the keys and re-indexes, which is the whole reason
        // there is no array_values() here: the tags were keyed by themselves
        // only to deduplicate.
        sort($tags);

        return $tags;
    }

    /**
     * Each channel's own payload, keyed by the channel it was granted for.
     *
     * A map rather than one payload, because there is no honest single answer:
     * merging two applications' payloads would invent one neither of them
     * signed, and picking one of them made the ANSWER CLIENT-CONTROLLABLE.
     * ConnectionGrants::mint() forgets and reassigns, so a re-subscribe moves a
     * channel to the end of the map, which let a client choose which of its
     * payloads the application would be shown by choosing what to re-subscribe
     * to. Every payload, addressed by the channel it belongs to, is the answer
     * that has no order in it.
     *
     * A connection the application never tagged has no grants and therefore an
     * empty map, which is the same "no" that a missing key is.
     *
     * @param  array<array-key, \Lightspeed\Auth\Grant>  $grants
     * @return array<array-key, array<array-key, mixed>>
     */
    private function authPayloadsOf(array $grants): array
    {
        $payloads = [];

        foreach ($grants as $channel => $grant) {
            // No cast on the key, because there is no cast that would survive:
            // PHP stores the array key "7" as the integer 7, so a numeric
            // channel name arrives as an int here exactly as it does in
            // ConnectionGrants, and an application keying off a channel name
            // has to know that.
            $payloads[$channel] = $grant->payload;
        }

        return $payloads;
    }
}
