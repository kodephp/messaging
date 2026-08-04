<?php

declare(strict_types=1);

namespace Kode\Messaging\Support;

/**
 * MQTT 主题过滤器匹配（MQTT 3.1.1 §4.7 / MQTT 5.0 §4.7）
 *
 * 规则：
 *  - `+` 匹配恰好一个层级
 *  - `#` 匹配零个或多个层级，且必须是最后一个 token
 *  - 通配符不匹配以 `$` 开头的主题（`$SYS/...`），见 §4.7.2
 *
 * 性能：Broker 每收到一条 PUBLISH，都要对「所有会话 × 每个订阅过滤器」做匹配。
 * 因此这里做两件事：
 *  1. filter 的层级切分结果按 filter 缓存（过滤器数量有限、生命周期长）；
 *  2. 提供 matchesParts()，让调用方对 topic 只 explode 一次，在会话循环内复用。
 */
final class TopicMatcher
{
    /** 过滤器切分缓存上限，防止恶意客户端用海量随机过滤器撑爆内存 */
    private const CACHE_LIMIT = 4096;

    /** @var array<string, list<string>> filter → 层级切分结果 */
    private static array $filterCache = [];

    /**
     * 过滤器是否匹配主题。
     */
    public static function matches(string $filter, string $topic): bool
    {
        if ($filter === $topic) {
            return true;
        }
        return self::matchesParts($filter, explode('/', $topic));
    }

    /**
     * 过滤器是否匹配「已切分」的主题层级。
     *
     * @param list<string> $topicParts explode('/', $topic) 的结果，可在一次发布内复用
     */
    public static function matchesParts(string $filter, array $topicParts): bool
    {
        $filterParts = self::filterParts($filter);

        // §4.7.2：以 $ 开头的主题不被首层通配符匹配（$SYS 等系统主题）
        $firstTopic = $topicParts[0] ?? '';
        if ($firstTopic !== '' && $firstTopic[0] === '$') {
            $firstFilter = $filterParts[0];
            if ($firstFilter === '#' || $firstFilter === '+') {
                return false;
            }
        }

        $i = 0;
        $filterLen = count($filterParts);
        $topicLen = count($topicParts);

        while ($i < $filterLen) {
            $f = $filterParts[$i];

            // `#`：匹配剩余所有层级（含零层），且必须是最后一个 token
            if ($f === '#') {
                return $i === $filterLen - 1;
            }

            // `+`：匹配恰好一个层级
            if ($f === '+') {
                if ($i >= $topicLen) {
                    return false;
                }
                $i++;
                continue;
            }

            if ($i >= $topicLen || $f !== $topicParts[$i]) {
                return false;
            }
            $i++;
        }

        return $i === $topicLen;
    }

    /**
     * 切分主题（供调用方在发布循环外调用一次）。
     *
     * @return list<string>
     */
    public static function split(string $topic): array
    {
        return explode('/', $topic);
    }

    /**
     * 过滤器是否合法：`#` 只能出现在末尾且独占一层，`+` 必须独占一层。
     */
    public static function isValidFilter(string $filter): bool
    {
        if ($filter === '') {
            return false;
        }
        $parts = self::filterParts($filter);
        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if (str_contains($part, '#') && ($part !== '#' || $i !== $last)) {
                return false;
            }
            if (str_contains($part, '+') && $part !== '+') {
                return false;
            }
        }
        return true;
    }

    /**
     * 清空缓存（测试 / 长驻进程回收用）。
     */
    public static function clearCache(): void
    {
        self::$filterCache = [];
    }

    /**
     * @return list<string>
     */
    private static function filterParts(string $filter): array
    {
        if (isset(self::$filterCache[$filter])) {
            return self::$filterCache[$filter];
        }
        $parts = explode('/', $filter);
        if (count(self::$filterCache) >= self::CACHE_LIMIT) {
            // 超限直接整体丢弃（摊还成本极低），避免无界增长
            self::$filterCache = [];
        }
        self::$filterCache[$filter] = $parts;
        return $parts;
    }
}
