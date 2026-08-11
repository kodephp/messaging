<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Mqtt\Server as MqttServer;
use Kode\Messaging\Support\TopicMatcher;
use PHPUnit\Framework\TestCase;

/**
 * MQTT 主题匹配器单元测试
 *
 * 覆盖：
 *  - 精确 / `+` 单级 / `#` 多级
 *  - `$SYS` 系统主题不被首层通配符匹配（MQTT §4.7.2）
 *  - matchesParts() 与 matches() 结果一致（预切分复用路径）
 *  - filter 切分缓存不影响结果
 *  - isValidFilter()
 *  - Server::matchTopic() 委托后行为不变
 */
final class TopicMatcherTest extends TestCase
{
    protected function setUp(): void
    {
        TopicMatcher::clearCache();
    }

    public function test_exact_match(): void
    {
        $this->assertTrue(TopicMatcher::matches('a/b', 'a/b'));
        $this->assertFalse(TopicMatcher::matches('a/b', 'a/c'));
    }

    public function test_single_level_wildcard(): void
    {
        $this->assertTrue(TopicMatcher::matches('sport/+/player', 'sport/tennis/player'));
        $this->assertFalse(TopicMatcher::matches('sport/+/player', 'sport/tennis/player/1'));
        $this->assertFalse(TopicMatcher::matches('sport/+/player', 'sport/player'));
        $this->assertTrue(TopicMatcher::matches('+', 'sport'));
        $this->assertFalse(TopicMatcher::matches('+', 'sport/tennis'));
    }

    public function test_multi_level_wildcard(): void
    {
        $this->assertTrue(TopicMatcher::matches('sport/#', 'sport'));
        $this->assertTrue(TopicMatcher::matches('sport/#', 'sport/tennis/player/1'));
        $this->assertFalse(TopicMatcher::matches('sport/#', 'sports/tennis'));
        $this->assertTrue(TopicMatcher::matches('#', 'a/b/c'));
    }

    public function test_dollar_topics_are_not_matched_by_leading_wildcard(): void
    {
        // MQTT §4.7.2：# 与 + 不匹配以 $ 开头的主题
        $this->assertFalse(TopicMatcher::matches('#', '$SYS/broker/uptime'));
        $this->assertFalse(TopicMatcher::matches('+/broker/uptime', '$SYS/broker/uptime'));
        // 显式写出 $SYS 前缀则可以匹配
        $this->assertTrue(TopicMatcher::matches('$SYS/#', '$SYS/broker/uptime'));
        $this->assertTrue(TopicMatcher::matches('$SYS/broker/+', '$SYS/broker/uptime'));
    }

    public function test_matches_parts_equals_matches(): void
    {
        $cases = [
            ['sport/+/player', 'sport/tennis/player'],
            ['sport/#', 'sport'],
            ['a/b', 'a/b/c'],
            ['#', '$SYS/x'],
            ['a/b', 'a/b'],
        ];
        foreach ($cases as [$filter, $topic]) {
            $this->assertSame(
                TopicMatcher::matches($filter, $topic),
                $filter === $topic || TopicMatcher::matchesParts($filter, TopicMatcher::split($topic)),
                "{$filter} vs {$topic}",
            );
        }
    }

    public function test_cache_does_not_change_result(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(TopicMatcher::matches('a/+/c', 'a/b/c'));
            $this->assertFalse(TopicMatcher::matches('a/+/c', 'a/b/d'));
        }
        TopicMatcher::clearCache();
        $this->assertTrue(TopicMatcher::matches('a/+/c', 'a/b/c'));
    }

    public function test_is_valid_filter(): void
    {
        $this->assertTrue(TopicMatcher::isValidFilter('a/b'));
        $this->assertTrue(TopicMatcher::isValidFilter('a/+/b'));
        $this->assertTrue(TopicMatcher::isValidFilter('a/#'));
        $this->assertTrue(TopicMatcher::isValidFilter('#'));
        $this->assertFalse(TopicMatcher::isValidFilter(''));
        $this->assertFalse(TopicMatcher::isValidFilter('a/#/b'));
        $this->assertFalse(TopicMatcher::isValidFilter('a/b#'));
        $this->assertFalse(TopicMatcher::isValidFilter('a/b+/c'));
    }

    public function test_server_match_topic_delegates(): void
    {
        $this->assertTrue(MqttServer::matchTopic('sport/+/player', 'sport/tennis/player'));
        $this->assertTrue(MqttServer::matchTopic('sport/#', 'sport'));
        $this->assertFalse(MqttServer::matchTopic('/sport/#', 'sport/tennis'));
        $this->assertTrue(MqttServer::matchTopic('#', ''));
    }
}
