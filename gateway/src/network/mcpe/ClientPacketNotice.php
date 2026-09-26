<?php
declare(strict_types=1);
namespace mpe\network\mcpe;
final class ClientPacketNotice {
    public static function summary(int $type,int $severity,int $id,string $message): string {
        // Arbitrary client text can contain secrets, control codes or forged log entries.
        // Log only numeric fields, bounded length and digest, never JWT or raw payloads.
        return sprintf('type=%d severity=%d packet=0x%x message_bytes=%d message_sha256=%s',
            $type,$severity,$id,strlen($message),hash('sha256',$message));
    }
}
