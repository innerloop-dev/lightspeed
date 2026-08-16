<?php

namespace Lightspeed\Presence;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed presence membership store for one logical channel.
 *
 * Connection ids map to user ids, user ids keep a reference count, and the
 * stored member payload stays normalized so any worker can rebuild a presence
 * snapshot without consulting local process state.
 *
 * WHY THERE IS A LIVENESS MARKER, AND WHY A TTL ALONE IS NOT ENOUGH
 *
 * Membership used to be removed by exactly one thing: the server's close
 * handler calling leave(). That made every row conditional on a close arriving,
 * and a SIGKILL, an OOM kill, a segfaulting worker or a container stop that
 * reaps the process never delivers one. The rows are REFERENCE COUNTS, so a
 * survivor is not merely stale: it is a member of every future snapshot for
 * that channel and there is no decrement left in the world that can remove it.
 *
 * A blanket EXPIRE on the hashes cannot fix that on its own. Redis cannot
 * expire an individual hash field before 7.4, so a TTL that is short enough to
 * clear ghosts also deletes the live members sharing the hash, and one long
 * enough to be safe does not clear anything on a useful timescale.
 *
 * So membership is paired with per-connection evidence that the connection is
 * still there, and something has to keep saying so:
 *
 *   join()               -> row + liveness marker, in one atomic script
 *   sweeper, every tick  -> heartbeatConnections()  refreshes the markers of
 *                           EVERY connection this worker can still see, on
 *                           every channel it holds, in one pipeline
 *   sweeper, on rotation -> heartbeatChannel()  refreshes the channel's own
 *                           TTL, the backstop for a channel every worker left
 *                        -> reapAbandoned()  leaves, for real, every row whose
 *                           marker is gone, decrementing the refcount, freeing
 *                           the colour slot, and reporting the user id so the
 *                           channel can be told
 *   leave()              -> row + marker, together
 *
 * The two heartbeats run at different rates because they are racing different
 * clocks. A marker lives 60s and must be rewritten before it lapses under a
 * connected member, so every channel gets one every tick. The channel keys live
 * an hour and are only ever reached by a channel nobody is on, so refreshing
 * them on the sweep's rotation costs a channel nothing.
 *
 * A killed worker stops heartbeating, its markers lapse, and the next worker
 * to sweep that channel unwinds its rows through the same script an orderly
 * close would have used. The channel-wide TTL stays as well, refreshed by the
 * same heartbeat: it is what reclaims a channel that every worker has left, so
 * nothing has a local subscriber to sweep it from.
 */
class PresenceStore
{
    private const LUA_JOIN_SCRIPT = <<<'LUA'
local existingUserId = redis.call('HGET', KEYS[1], ARGV[1])
local paletteSize = tonumber(ARGV[4]) or 0
local userInfo = {}

if ARGV[3] and ARGV[3] ~= '' then
    userInfo = cjson.decode(ARGV[3])
end

local function deriveActorKey(info, defaultUserId)
    local explicitActorKey = info['actorKey']
    if explicitActorKey and explicitActorKey ~= cjson.null and explicitActorKey ~= '' then
        return tostring(explicitActorKey)
    end

    return tostring(defaultUserId)
end

local function activeActorKeys()
    local seen = {}
    local actors = {}

    for _, actorKey in ipairs(redis.call('HVALS', KEYS[5])) do
        if actorKey and actorKey ~= '' and not seen[actorKey] then
            seen[actorKey] = true
            table.insert(actors, actorKey)
        end
    end

    local rawUsers = redis.call('HGETALL', KEYS[3])
    for index = 1, #rawUsers, 2 do
        local encodedUserInfo = rawUsers[index + 1]
        if encodedUserInfo and encodedUserInfo ~= '' then
            local ok, decoded = pcall(cjson.decode, encodedUserInfo)
            if ok and type(decoded) == 'table' then
                local actorKey = deriveActorKey(decoded, rawUsers[index])
                if actorKey and actorKey ~= '' and not seen[actorKey] then
                    seen[actorKey] = true
                    table.insert(actors, actorKey)
                end
            end
        end
    end

    return actors
end

local function assignColorIndex(actorKey)
    -- Disabled means disabled, including for an actor who was given a colour
    -- while the feature was on. Reading the cached colour first would keep
    -- injecting colorIndex into member payloads after an app turned the
    -- palette off, which is the one thing the config promises cannot happen.
    if paletteSize <= 0 then
        return -1
    end

    local existingColor = redis.call('HGET', KEYS[4], actorKey)
    if existingColor then
        return tonumber(existingColor)
    end

    local used = {}
    local activeActors = activeActorKeys()
    for _, activeActorKey in ipairs(activeActors) do
        if activeActorKey ~= actorKey then
            local assignedColor = redis.call('HGET', KEYS[4], activeActorKey)
            local idx = tonumber(assignedColor)
            if idx then
                used[idx] = true
            end
        end
    end

    for idx = 0, paletteSize - 1 do
        if not used[idx] then
            redis.call('HSET', KEYS[4], actorKey, tostring(idx))
            return idx
        end
    end

    local hashHex = string.sub(redis.sha1hex(actorKey), -8)
    local fallback = tonumber(hashHex, 16) or 0
    local idx = fallback % paletteSize
    redis.call('HSET', KEYS[4], actorKey, tostring(idx))
    return idx
end

local function releaseActorColorIfUnused(actorKey)
    if not actorKey or actorKey == '' then
        return
    end

    for _, activeActorKey in ipairs(activeActorKeys()) do
        if activeActorKey == actorKey then
            return
        end
    end

    redis.call('HDEL', KEYS[4], actorKey)
end

local function releasePresenceUser(userId)
    local priorCount = tonumber(redis.call('HINCRBY', KEYS[2], userId, -1))
    if priorCount <= 0 then
        local actorKey = redis.call('HGET', KEYS[5], userId)
        local encodedUserInfo = redis.call('HGET', KEYS[3], userId)

        redis.call('HDEL', KEYS[2], userId)
        redis.call('HDEL', KEYS[5], userId)
        redis.call('HDEL', KEYS[3], userId)

        if (not actorKey or actorKey == '') and encodedUserInfo and encodedUserInfo ~= '' then
            local ok, decoded = pcall(cjson.decode, encodedUserInfo)
            if ok and type(decoded) == 'table' then
                actorKey = deriveActorKey(decoded, userId)
            end
        end

        releaseActorColorIfUnused(actorKey and tostring(actorKey) or '')
    end
end

-- The evidence that this connection is still there, written in the same script
-- as the row it vouches for. Doing it in a second round trip would leave a
-- window in which a process that died in between left a row with no marker,
-- and the sweeper would reap a member who had just legitimately joined.
local function markConnectionLive()
    redis.call('SET', KEYS[6], ARGV[2], 'EX', tonumber(ARGV[5]))

    -- Refreshed on every join so a channel with any traffic at all never
    -- approaches it. This TTL is not what removes ghosts (the marker is); it
    -- is what reclaims a channel every worker has gone away from, which no
    -- sweeper is left to walk.
    local channelTtl = tonumber(ARGV[6])
    redis.call('EXPIRE', KEYS[1], channelTtl)
    redis.call('EXPIRE', KEYS[2], channelTtl)
    redis.call('EXPIRE', KEYS[3], channelTtl)
    redis.call('EXPIRE', KEYS[4], channelTtl)
    redis.call('EXPIRE', KEYS[5], channelTtl)
end

local actorKey = deriveActorKey(userInfo, ARGV[2])

if existingUserId and existingUserId == ARGV[2] then
    redis.call('HSET', KEYS[5], ARGV[2], actorKey)
    local colorIndex = assignColorIndex(actorKey)
    if colorIndex >= 0 then
        userInfo['colorIndex'] = colorIndex
    end
    redis.call('HSET', KEYS[3], ARGV[2], cjson.encode(userInfo))
    markConnectionLive()
    return {0, colorIndex}
end

if existingUserId and existingUserId ~= ARGV[2] then
    releasePresenceUser(existingUserId)
end

redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
local nextCount = tonumber(redis.call('HINCRBY', KEYS[2], ARGV[2], 1))
local colorIndex = assignColorIndex(actorKey)
if colorIndex >= 0 then
    userInfo['colorIndex'] = colorIndex
end
redis.call('HSET', KEYS[5], ARGV[2], actorKey)
redis.call('HSET', KEYS[3], ARGV[2], cjson.encode(userInfo))
markConnectionLive()

if nextCount == 1 then
    return {1, colorIndex}
end

return {0, colorIndex}
LUA;

    private const LUA_LEAVE_SCRIPT = <<<'LUA'
local userId = redis.call('HGET', KEYS[1], ARGV[1])

-- Unconditionally, and before the early return: a marker with no row behind it
-- is what a half-finished join or a previous partial teardown leaves, and it
-- must not outlive the leave that was meant to clear it.
redis.call('DEL', KEYS[6])

if not userId then
    return {0, '', -1}
end

local function deriveActorKey(info, defaultUserId)
    local explicitActorKey = info['actorKey']
    if explicitActorKey and explicitActorKey ~= cjson.null and explicitActorKey ~= '' then
        return tostring(explicitActorKey)
    end

    return tostring(defaultUserId)
end

local function activeActorKeys()
    local seen = {}
    local actors = {}

    for _, actorKey in ipairs(redis.call('HVALS', KEYS[5])) do
        if actorKey and actorKey ~= '' and not seen[actorKey] then
            seen[actorKey] = true
            table.insert(actors, actorKey)
        end
    end

    local rawUsers = redis.call('HGETALL', KEYS[3])
    for index = 1, #rawUsers, 2 do
        local encodedUserInfo = rawUsers[index + 1]
        if encodedUserInfo and encodedUserInfo ~= '' then
            local ok, decoded = pcall(cjson.decode, encodedUserInfo)
            if ok and type(decoded) == 'table' then
                local actorKey = deriveActorKey(decoded, rawUsers[index])
                if actorKey and actorKey ~= '' and not seen[actorKey] then
                    seen[actorKey] = true
                    table.insert(actors, actorKey)
                end
            end
        end
    end

    return actors
end

local function releaseActorColorIfUnused(actorKey)
    if not actorKey or actorKey == '' then
        return
    end

    for _, activeActorKey in ipairs(activeActorKeys()) do
        if activeActorKey == actorKey then
            return
        end
    end

    redis.call('HDEL', KEYS[4], actorKey)
end

redis.call('HDEL', KEYS[1], ARGV[1])

local nextCount = tonumber(redis.call('HINCRBY', KEYS[2], userId, -1))
if nextCount <= 0 then
    local actorKey = redis.call('HGET', KEYS[5], userId)
    if (not actorKey or actorKey == '') then
        local encodedUserInfo = redis.call('HGET', KEYS[3], userId)
        if encodedUserInfo and encodedUserInfo ~= '' then
            local ok, decoded = pcall(cjson.decode, encodedUserInfo)
            if ok and type(decoded) == 'table' then
                actorKey = deriveActorKey(decoded, userId)
            end
        end
    end

    redis.call('HDEL', KEYS[2], userId)
    redis.call('HDEL', KEYS[3], userId)
    redis.call('HDEL', KEYS[5], userId)
    releaseActorColorIfUnused(actorKey and tostring(actorKey) or '')
    return {1, userId, -1}
end

return {0, userId, -1}
LUA;

    public function __construct(
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Attach one connection to the presence snapshot for a channel.
     *
     * The Lua script keeps the connection->user mapping, per-user reference
     * counts, actor-key color assignment, and the returned snapshot in sync.
     */
    public function join(string $channel, string $connectionId, array $member): array
    {
        $userId = (string) ($member['user_id'] ?? '');
        if ($userId === '') {
            return [
                'broadcast_member_added' => false,
                'snapshot' => $this->snapshot($channel),
            ];
        }

        $userInfo = $member['user_info'] ?? new \stdClass();
        $added = Redis::connection($this->redisConnection())->eval(
            self::LUA_JOIN_SCRIPT,
            6,
            $this->connectionsKey($channel),
            $this->userCountsKey($channel),
            $this->usersKey($channel),
            $this->colorsKey($channel),
            $this->userActorsKey($channel),
            $this->livenessKey($channel, $connectionId),
            $connectionId,
            $userId,
            json_encode($userInfo, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $this->colorSlots(),
            $this->connectionTtlSeconds(),
            $this->channelTtlSeconds(),
        );

        $memberInfo = $this->member($channel, $userId) ?? $userInfo;

        return [
            'broadcast_member_added' => (int) ($added[0] ?? 0) === 1,
            'snapshot' => $this->snapshot($channel),
            'member' => [
                'user_id' => $userId,
                'user_info' => $memberInfo,
            ],
        ];
    }

    /**
     * Remove one connection from a presence channel and report whether the
     * logical member disappeared entirely from the shared snapshot.
     */
    public function leave(string $channel, string $connectionId): array
    {
        $result = Redis::connection($this->redisConnection())->eval(
            self::LUA_LEAVE_SCRIPT,
            6,
            $this->connectionsKey($channel),
            $this->userCountsKey($channel),
            $this->usersKey($channel),
            $this->colorsKey($channel),
            $this->userActorsKey($channel),
            $this->livenessKey($channel, $connectionId),
            $connectionId,
        );

        $removed = (int) ($result[0] ?? 0) === 1;
        $userId = (string) ($result[1] ?? '');

        return [
            'broadcast_member_removed' => $removed,
            'user_id' => $userId,
        ];
    }

    /**
     * Say that these connections are still on these channels.
     *
     * Called by each worker's presence sweeper for the connections it can still
     * see locally, which is the only place in the system that knows. A worker
     * that stops running this (because it was killed, not because it chose to)
     * is exactly the worker whose rows should stop being believed.
     *
     * TAKES A WHOLE CHUNK OF CHANNELS RATHER THAN ONE, because the marker is
     * racing a wall-clock TTL and the sweep's per-tick channel budget is not
     * allowed to be in that race. A worker holding more channels than one tick
     * sweeps used to refresh a channel's markers once every
     * ceil(channels / budget) ticks, and past the point where that period
     * overtakes the TTL, the markers of CONNECTED members lapsed and another
     * worker reaped them. The sweeper walks every channel it holds through this
     * call on every tick, a chunk to a pipeline.
     *
     * Pipelined, and not as a micro-optimization: a round trip per connection
     * would be one Redis RTT per connection per tick, and this worker cannot
     * yield while it waits.
     *
     * @param array<string, list<string>> $connectionIdsByChannel
     */
    public function heartbeatConnections(array $connectionIdsByChannel): void
    {
        $connectionTtl = $this->connectionTtlSeconds();
        $markerKeys = [];

        foreach ($connectionIdsByChannel as $channel => $connectionIds) {
            foreach ($connectionIds as $connectionId) {
                if (is_string($connectionId) && $connectionId !== '') {
                    $markerKeys[] = $this->livenessKey((string) $channel, $connectionId);
                }
            }
        }

        // Nothing to say, and an empty pipeline is still a round trip.
        if ($markerKeys === []) {
            return;
        }

        Redis::connection($this->redisConnection())->pipeline(
            static function ($pipe) use ($markerKeys, $connectionTtl): void {
                foreach ($markerKeys as $key) {
                    // SETEX rather than EXPIRE: a marker whose TTL lapsed
                    // between the last tick and this one is gone, and EXPIRE on
                    // a missing key does nothing. The connection is demonstrably
                    // still here, so the marker is rewritten, not extended.
                    //
                    // SETEX rather than SET..EX as well: inside a pipeline the
                    // calls go to the raw phpredis client, whose set() takes at
                    // most three arguments and throws on the options form.
                    $pipe->setex($key, $connectionTtl, '1');
                }
            }
        );
    }

    /**
     * Push back the expiry of the five keys one channel's state lives in.
     *
     * The backstop, not the mechanism: this TTL is only ever reached by a
     * channel no worker is left holding, so it is the sweep's rotation that
     * refreshes it rather than the every-tick marker pass. A channel that waits
     * a few ticks for its turn is nowhere near an hour.
     */
    public function heartbeatChannel(string $channel): void
    {
        $channelTtl = $this->channelTtlSeconds();
        $channelKeys = $this->channelKeys($channel);

        Redis::connection($this->redisConnection())->pipeline(
            static function ($pipe) use ($channelKeys, $channelTtl): void {
                foreach ($channelKeys as $key) {
                    $pipe->expire($key, $channelTtl);
                }
            }
        );
    }

    /**
     * Remove every membership row on this channel whose connection is gone.
     *
     * "Gone" means the liveness marker has lapsed: no worker has vouched for
     * that connection for longer than the marker's TTL, which for a live
     * connection cannot happen because its worker heartbeats it many times over
     * within that window.
     *
     * The removal runs through the ordinary leave script, deliberately. A ghost
     * is not a row to delete. It is a reference count to decrement, a colour
     * slot to release and possibly a member the channel has to be told about,
     * and reimplementing that here is how the two would drift apart.
     *
     * Safe to run on several workers at once: the script is atomic, so the
     * second worker to reach the same ghost sees no row and reports nothing.
     *
     * @return list<string> user ids that disappeared from the channel entirely
     */
    public function reapAbandoned(string $channel): array
    {
        $connection = Redis::connection($this->redisConnection());
        $connectionIds = $connection->hkeys($this->connectionsKey($channel));

        if (!is_array($connectionIds) || $connectionIds === []) {
            return [];
        }

        $removed = [];

        foreach ($connectionIds as $connectionId) {
            $connectionId = (string) $connectionId;

            if ($connectionId === '' || (bool) $connection->exists($this->livenessKey($channel, $connectionId))) {
                continue;
            }

            $result = $this->leave($channel, $connectionId);

            if ($result['broadcast_member_removed'] && $result['user_id'] !== '') {
                $removed[] = $result['user_id'];
            }
        }

        return $removed;
    }

    /** Build the normalized Pusher-style presence snapshot for one channel. */
    public function snapshot(string $channel): array
    {
        $users = Redis::connection($this->redisConnection())->hgetall($this->usersKey($channel));
        if (!is_array($users) || $users === []) {
            return [
                'ids' => [],
                // An empty object, not an empty array: the Pusher protocol says
                // hash is an object and clients index into it.
                'hash' => new \stdClass(),
                'count' => 0,
            ];
        }

        $ids = [];
        $hash = [];

        foreach ($users as $userId => $encodedUserInfo) {
            // PHP casts numeric array keys to int, and Redis hash fields come
            // back as an array, so a user_id of "42" arrives here as int 42.
            // Laravel's default primary key is an integer, so treating a
            // non-string key as junk silently emptied the presence snapshot
            // for the most ordinary app there is.
            $userId = (string) $userId;

            if ($userId === '') {
                continue;
            }

            $ids[] = $userId;

            if (!is_string($encodedUserInfo) || $encodedUserInfo === '') {
                $hash[$userId] = new \stdClass();
                continue;
            }

            try {
                $hash[$userId] = json_decode($encodedUserInfo, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $hash[$userId] = new \stdClass();
            }
        }

        return [
            'ids' => $ids,
            // Always an object. PHP re-casts a numeric string key back to int,
            // so a hash keyed 0..n-1 (or an empty one) would otherwise encode
            // as a JSON array, and the Pusher protocol says clients index into
            // this by user id.
            'hash' => (object) $hash,
            'count' => count($ids),
        ];
    }

    /** Read the stored member payload for one logical user id. */
    public function member(string $channel, string $userId): ?array
    {
        if ($userId === '') {
            return null;
        }

        $encodedUserInfo = Redis::connection($this->redisConnection())->hget($this->usersKey($channel), $userId);
        if (!is_string($encodedUserInfo) || $encodedUserInfo === '') {
            return null;
        }

        try {
            $decoded = json_decode($encodedUserInfo, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function connectionsKey(string $channel): string
    {
        return "lightspeed:presence:channel:{$channel}:connections";
    }

    private function userCountsKey(string $channel): string
    {
        return "lightspeed:presence:channel:{$channel}:user-counts";
    }

    private function usersKey(string $channel): string
    {
        return "lightspeed:presence:channel:{$channel}:users";
    }

    private function colorsKey(string $channel): string
    {
        return "lightspeed:presence:channel:{$channel}:colors";
    }

    private function userActorsKey(string $channel): string
    {
        return "lightspeed:presence:channel:{$channel}:actors";
    }

    /**
     * The key whose existence means "this connection is still on this channel".
     *
     * Scoped to the channel as well as the connection, not to the connection
     * alone. One connection can hold several presence channels, and a shared
     * marker would be deleted by the first channel it left, after which the
     * reconciliation would find no marker for its still-live memberships on
     * every other channel and reap a member who never went anywhere.
     */
    private function livenessKey(string $channel, string $connectionId): string
    {
        return "lightspeed:presence:channel:{$channel}:live:{$connectionId}";
    }

    /** @return list<string> the five per-channel keys that share one TTL */
    private function channelKeys(string $channel): array
    {
        return [
            $this->connectionsKey($channel),
            $this->userCountsKey($channel),
            $this->usersKey($channel),
            $this->colorsKey($channel),
            $this->userActorsKey($channel),
        ];
    }

    private function redisConnection(): string
    {
        return (string) $this->config->get('lightspeed.presence.redis_connection', 'default');
    }

    /**
     * Size of the optional presence colour palette.
     *
     * Zero (the default) disables colour assignment entirely, and no
     * `colorIndex` is added to any member payload. The floor is 0, not 1: an
     * app that turns the feature off must actually get it turned off.
     */
    private function colorSlots(): int
    {
        return max(0, (int) $this->config->get('lightspeed.presence.color_slots', 0));
    }

    /**
     * How long a membership is believed without being vouched for again.
     *
     * This is the granularity of ghost removal: a connection killed without a
     * close stays in snapshots for at most this long. It must stay comfortably
     * above the sweep interval, because a marker that lapses between two ticks
     * of a HEALTHY worker would have that worker reap its own live members.
     * The floor enforces the relationship rather than trusting the operator to
     * keep the two settings in step.
     */
    private function connectionTtlSeconds(): int
    {
        $sweepIntervalSeconds = (int) ceil(
            max(100, (int) $this->config->get('lightspeed.presence.sweep_interval_ms', 10000)) / 1000
        );

        return max(
            $sweepIntervalSeconds * 3,
            (int) $this->config->get('lightspeed.presence.connection_ttl_seconds', 60),
        );
    }

    /**
     * How long a channel's rows survive with nothing refreshing them.
     *
     * Only reached when NO worker anywhere holds a local subscriber for the
     * channel, because any that does refreshes this on every sweep. That makes
     * it the backstop for the one case the markers cannot cover: every worker
     * on a channel dying at once leaves rows that nothing is left to walk.
     */
    private function channelTtlSeconds(): int
    {
        return max(
            $this->connectionTtlSeconds() * 2,
            (int) $this->config->get('lightspeed.presence.channel_ttl_seconds', 3600),
        );
    }
}
