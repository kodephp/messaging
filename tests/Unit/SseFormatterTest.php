<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Sse\Formatter;
use Kode\Messaging\Message\Message;
use PHPUnit\Framework\TestCase;

/**
 * SSE Formatter 单元测试
 *
 * 覆盖：
 *  - 基本 data 格式化
 *  - event / id / retry 字段
 *  - 多行 data
 *  - sanitize（\r 和 \n 被替换）
 *  - fromMessage 转换
 *  - 空数据
 */
final class SseFormatterTest extends TestCase
{
    public function testBasicDataFormat(): void
    {
        $output = Formatter::format('hello');
        $this->assertSame("data: hello\n\n", $output);
    }

    public function testEventField(): void
    {
        $output = Formatter::format('payload', event: 'update');
        $this->assertStringContainsString("event: update\n", $output);
        $this->assertStringContainsString("data: payload\n", $output);
        $this->assertStringEndsWith("\n\n", $output);
    }

    public function testIdField(): void
    {
        $output = Formatter::format('data', id: '42');
        $this->assertStringContainsString("id: 42\n", $output);
    }

    public function testRetryField(): void
    {
        $output = Formatter::format('data', retry: 5000);
        $this->assertStringContainsString("retry: 5000\n", $output);
    }

    public function testAllFields(): void
    {
        $output = Formatter::format('body', event: 'message', id: '100', retry: 3000);
        $this->assertStringContainsString("id: 100\n", $output);
        $this->assertStringContainsString("event: message\n", $output);
        $this->assertStringContainsString("retry: 3000\n", $output);
        $this->assertStringContainsString("data: body\n", $output);
    }

    public function testMultiLineData(): void
    {
        $output = Formatter::format("line1\nline2\nline3");
        $this->assertStringContainsString("data: line1\n", $output);
        $this->assertStringContainsString("data: line2\n", $output);
        $this->assertStringContainsString("data: line3\n", $output);
    }

    public function testSanitizeRemovesCarriageReturn(): void
    {
        // \r 被 sanitize 移除；\n 导致 data 分行
        $output = Formatter::format("hello\r\nworld");
        $this->assertStringNotContainsString("\r", $output);
        // 第一行 "hello\r" → sanitize → "hello"（\r 被移除）
        $this->assertStringContainsString("data: hello\n", $output);
        $this->assertStringContainsString("data: world\n", $output);
    }

    public function testSanitizeReplacesNewlineInEvent(): void
    {
        $output = Formatter::format('data', event: "evt\ninjected");
        // \n 在 event 值中应被替换为空格
        $this->assertStringContainsString("event: evt injected\n", $output);
    }

    public function testEmptyData(): void
    {
        $output = Formatter::format('');
        $this->assertSame("data: \n\n", $output);
    }

    public function testFieldOrder(): void
    {
        $output = Formatter::format('payload', event: 'evt', id: '1', retry: 2000);
        // 顺序应为 id → event → retry → data
        $idPos = strpos($output, 'id:');
        $eventPos = strpos($output, 'event:');
        $retryPos = strpos($output, 'retry:');
        $dataPos = strpos($output, 'data:');

        $this->assertLessThan($eventPos, $idPos);
        $this->assertLessThan($retryPos, $eventPos);
        $this->assertLessThan($dataPos, $retryPos);
    }

    // ===================== fromMessage =====================

    public function testFromMessageStringPayload(): void
    {
        $msg = Message::fromRaw('hello', 'sse', event: 'update');
        $output = Formatter::fromMessage($msg);
        $this->assertStringContainsString("event: update\n", $output);
        $this->assertStringContainsString("data: hello\n", $output);
        // id 由 IdGenerator 自动生成
        $this->assertStringContainsString("id:", $output);
    }

    public function testFromMessageArrayPayloadJsonEncoded(): void
    {
        // fromRaw 只接受 string，但 fromMessage 会检测 is_string
        $msg = Message::fromRaw('{"key":"value"}', 'sse', event: 'data');
        $output = Formatter::fromMessage($msg);
        $this->assertStringContainsString('data: {"key":"value"}', $output);
    }

    public function testFromMessageUnicodeNotEscaped(): void
    {
        $msg = Message::fromRaw('中文消息', 'sse');
        $output = Formatter::fromMessage($msg);
        $this->assertStringContainsString('data: 中文消息', $output);
    }
}
