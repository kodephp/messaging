<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Sse;

use Kode\Messaging\Adapter\WebSocket\Server as WsServer;
use Kode\Messaging\Message\Message as Msg;

/**
 * SSE 事件格式化（HTML5 Server-Sent Events）
 *
 * 字段：
 *  - event:   事件名
 *  - data:    数据（多行以\n 开头）
 *  - id:      事件 ID（用于断线续传）
 *  - retry:   客户端重试间隔（毫秒）
 *  - :comment 注释（保持连接）
 */
final class Formatter
{
    public static function format(
        string $data,
        ?string $event = null,
        ?string $id = null,
        ?int $retry = null,
    ): string {
        $output = '';
        if ($id !== null) {
            $output .= 'id: ' . self::sanitize($id) . "\n";
        }
        if ($event !== null) {
            $output .= 'event: ' . self::sanitize($event) . "\n";
        }
        if ($retry !== null) {
            $output .= 'retry: ' . $retry . "\n";
        }
        // data 可多行
        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $output .= 'data: ' . self::sanitize($line) . "\n";
        }
        $output .= "\n";
        return $output;
    }

    /**
     * 把 Message 对象转为 SSE 文本。
     */
    public static function fromMessage(Msg $message): string
    {
        $payload = $message->payload();
        $data = is_string($payload) ? $payload : (string)json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $id = $message->id();
        return self::format($data, $message->event(), $id);
    }

    private static function sanitize(string $line): string
    {
        // SSE 中 \r 会导致解析问题
        return str_replace(["\r", "\n"], ['', ' '], $line);
    }
}
