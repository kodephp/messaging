<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Rtmp;

use Kode\Messaging\Adapter\WebSocket\WebSocketConnection;

/**
 * RTMP 连接（基于 TCP 的流式连接）
 */
class RtmpConnection extends WebSocketConnection
{
    private int $chunkSizeIn = 128;

    private int $chunkSizeOut = 128;

    /** @var array<int, array{timestamp:int, messageLength:int, messageType:int, messageStreamId:int, buffer:string, fmt:int}> */
    private array $chunkStates = [];

    public function setChunkSizeIn(int $size): void
    {
        $this->chunkSizeIn = max(1, min(RtmpChunk::CHUNK_SIZE_MAX, $size));
    }

    public function setChunkSizeOut(int $size): void
    {
        $this->chunkSizeOut = max(1, min(RtmpChunk::CHUNK_SIZE_MAX, $size));
    }

    public function chunkSizeIn(): int
    {
        return $this->chunkSizeIn;
    }

    public function chunkSizeOut(): int
    {
        return $this->chunkSizeOut;
    }

    public function getChunkState(int $csid): ?array
    {
        return $this->chunkStates[$csid] ?? null;
    }

    public function setChunkState(int $csid, array $state): void
    {
        $this->chunkStates[$csid] = $state;
    }

    public function clearChunkState(int $csid): void
    {
        unset($this->chunkStates[$csid]);
    }

    public function sendRtmpChunk(int $csid, int $messageType, int $messageStreamId, string $body, int $timestamp = 0): bool
    {
        $offset = 0;
        $len = strlen($body);
        $first = true;
        while ($offset < $len) {
            $piece = substr($body, $offset, $this->chunkSizeOut);
            $offset += strlen($piece);
            $fmt = $first ? RtmpChunk::FMT_FULL : RtmpChunk::FMT_CONTINUATION;
            $ts = $first ? $timestamp : $timestamp; // 时间戳：只在首片携带
            $packet = RtmpChunk::encodeBasicHeader($fmt, $csid)
                .RtmpChunk::encodeMessageHeader(
                    $fmt,
                    $ts,
                    $len,
                    $messageType,
                    $messageStreamId,
                )
                .$piece;
            $written = @fwrite($this->stream, $packet);
            if ($written === false || $written < strlen($packet)) {
                return false;
            }
            $first = false;
        }

        return true;
    }

    /**
     * 解析一个或多个 chunk（消费 buffer，返回完整消息列表）。
     *
     * @return list<array{csid:int, type:int, timestamp:int, body:string}>
     */
    public function parseChunks(string $buffer, int &$consumed): array
    {
        $messages = [];
        $offset = 0;
        $len = strlen($buffer);
        while ($offset < $len) {
            $bh = RtmpChunk::decodeBasicHeader($buffer, $offset);
            if ($bh === null) {
                break;
            }
            $offset += $bh['consumed'];
            $csid = $bh['csid'];
            $fmt = $bh['fmt'];
            $state = $this->getChunkState($csid);
            $effectiveFmt = $fmt;
            if ($fmt === RtmpChunk::FMT_CONTINUATION && $state === null) {
                throw \Kode\Messaging\Exception\RtmpException::chunkError('无前置 chunk 状态', ['csid' => $csid]);
            }
            $mh = RtmpChunk::decodeMessageHeader($effectiveFmt, $buffer, $offset);
            if ($mh === null) {
                break;
            }
            $offset += $mh['consumed'];
            if ($fmt === RtmpChunk::FMT_CONTINUATION) {
                $mh = array_merge($state, $mh);
            }
            // 读取 body
            $remaining = $mh['messageLength'] - strlen($state['buffer'] ?? '');
            $toRead = min($remaining, $this->chunkSizeIn);
            if (strlen($buffer) < $offset + $toRead) {
                // 不够，留给下次
                break;
            }
            $piece = substr($buffer, $offset, $toRead);
            $offset += $toRead;
            $newBuffer = ($state['buffer'] ?? '').$piece;
            if (strlen($newBuffer) >= $mh['messageLength']) {
                $messages[] = [
                    'csid' => $csid,
                    'type' => $mh['messageType'],
                    'timestamp' => $mh['timestamp'],
                    'body' => $newBuffer,
                ];
                $this->clearChunkState($csid);
            } else {
                $this->setChunkState($csid, [
                    'timestamp' => $mh['timestamp'],
                    'messageLength' => $mh['messageLength'],
                    'messageType' => $mh['messageType'],
                    'messageStreamId' => $mh['messageStreamId'],
                    'buffer' => $newBuffer,
                    'fmt' => $fmt,
                ]);
            }
        }
        $consumed = $offset;

        return $messages;
    }

    public function send(mixed $payload, array $options = []): bool
    {
        // RTMP 由业务层使用 sendRtmpChunk；此处保留以满足接口
        return false;
    }
}
