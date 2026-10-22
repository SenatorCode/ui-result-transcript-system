<?php
// Owner: Dev A.

final class TranscriptData
{
    /** Before issue: live results, transcript number shown as pending. */
    public static function build(int $requestId): array { throw new RuntimeException('Not implemented'); }
}

final class TranscriptPdf
{
    /** Returns PDF bytes. */
    public static function render(array $data): string { throw new RuntimeException('Not implemented'); }
}

final class ReceiptPdf
{
    /** Renders the array returned by Payment::receiptData(). */
    public static function render(array $receipt): string { throw new RuntimeException('Not implemented'); }
}