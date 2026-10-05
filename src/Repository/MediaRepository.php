<?php
namespace CMS\Repository;

/**
 * Media records. `storage` is 'r2' (S3-compatible object storage) or
 * 'remote' (an externally hosted URL); the (storage, key) unique pair
 * keeps re-importing the same object idempotent.
 */
class MediaRepository extends Repository
{
    protected const TABLE = 'media';
    protected const COLUMNS = [
        'id', 'uuid', 'storage', 'key', 'url', 'mime', 'bytes',
        'width', 'height', 'alt', 'caption', 'checksum', 'uploaded_by', 'created_at',
    ];

    /** MIME types an image renderer may safely be pointed at. */
    public const SAFE_IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

    /** Extensions permitted for upload, mapped to their MIME type. */
    public const ALLOWED = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif',
        'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
        'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
        'txt' => 'text/plain', 'csv' => 'text/csv',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'zip' => 'application/zip',
    ];

    public function find(int $id): ?array
    {
        return $this->selectOne('id = ?', [$id]);
    }

    public function findByKey(string $storage, string $key): ?array
    {
        return $this->selectOne('storage = ? AND key = ?', [$storage, $key]);
    }

    /** Register an already-uploaded object. Idempotent on (storage, key). */
    public function register(array $data): int
    {
        $existing = $this->findByKey($data['storage'] ?? 'remote', $data['key'] ?? '');
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->insertRow([
            'uuid'        => $data['uuid'] ?? bin2hex(random_bytes(16)),
            'storage'     => $data['storage'] ?? 'remote',
            'key'         => $data['key'] ?? '',
            'url'         => $data['url'] ?? '',
            'mime'        => $data['mime'] ?? 'application/octet-stream',
            'bytes'       => (int) ($data['bytes'] ?? 0),
            'width'       => $data['width'] ?? null,
            'height'      => $data['height'] ?? null,
            'alt'         => $data['alt'] ?? null,
            'caption'     => $data['caption'] ?? null,
            'checksum'    => $data['checksum'] ?? null,
            'uploaded_by' => $data['uploaded_by'] ?? null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function paginate(array $filters = [], int $limit = 40, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['mime_prefix'])) {
            $where[] = 'mime LIKE ?';
            $params[] = $filters['mime_prefix'] . '%';
        }
        if (!empty($filters['search'])) {
            $where[] = '(key LIKE ? OR alt LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        $clause = implode(' AND ', $where);
        $limit  = max(1, min($limit, 200));
        $offset = max(0, $offset);

        return [
            'items' => $this->select($clause, $params, 'created_at DESC', 'LIMIT ? OFFSET ?', [$limit, $offset]),
            'total' => $this->count($clause, $params),
            'limit' => $limit,
            'offset'=> $offset,
        ];
    }

    public function update(int $id, array $data): bool
    {
        $allowed = array_intersect_key($data, array_flip(['alt', 'caption', 'url', 'width', 'height']));
        return $allowed === [] ? false : $this->updateRow($id, $allowed) >= 0;
    }

    public function delete(int $id): bool
    {
        // Detach from any post that featured it first — the FK is not
        // ON DELETE CASCADE here, so an orphan reference would 500 later.
        $this->db->getPdo()->prepare('UPDATE posts SET featured_media_id = NULL WHERE featured_media_id = ?')
            ->execute([$id]);
        return $this->deleteRow($id) > 0;
    }

    /**
     * Validate an upload before anything touches disk or the DB.
     *
     * @return array{ok:bool, mime?:string, ext?:string, error?:string, bytes?:int}
     */
    public static function validateUpload(string $filename, int $bytes, ?string $detectedMime = null): array
    {
        if ($bytes <= 0) {
            return ['ok' => false, 'error' => 'File is empty'];
        }
        if ($bytes > 25 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'File exceeds the 25MB limit'];
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' || !isset(self::ALLOWED[$ext])) {
            return ['ok' => false, 'error' => "File type '{$ext}' is not allowed"];
        }

        $expected = self::ALLOWED[$ext];

        /*
         * Every declared type is checked against the sniffed one, not just the
         * handful that carry scripts.
         *
         * The previous version compared only for svg/docx/zip. That looks like
         * a targeted defence against "SVG can carry <script>", but it lets the
         * same trick through on any other extension: an attacker renames a
         * payload to avatar.png and the check never runs, so the file is
         * accepted and — because $expected, not $detectedMime, is what gets
         * stored as its type — recorded as a PNG. The mismatch is not a
         * curiosity to police on three extensions; a declared type that
         * disagrees with the content is a lie either way.
         *
         * $detectedMime stays optional so callers without fileinfo (and the
         * static tests) still work; a caller that DOES sniff gets the check.
         */
        if ($detectedMime !== null && $detectedMime !== $expected) {
            return ['ok' => false, 'error' => "Extension '{$ext}' does not match the actual content type ({$detectedMime})"];
        }

        return ['ok' => true, 'mime' => $expected, 'ext' => $ext, 'bytes' => $bytes];
    }

    /** Content-addressed key: de-duplicates identical uploads. */
    public static function buildKey(string $filename, string $checksum): string
    {
        $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $stem = slugify(pathinfo($filename, PATHINFO_FILENAME));
        $year = date('Y');
        $month = date('m');
        return "media/{$year}/{$month}/" . substr($checksum, 0, 2) . "/{$stem}-" . substr($checksum, 0, 12) . ".{$ext}";
    }

    public function totalBytes(): int
    {
        $stmt = $this->db->getPdo()->prepare('SELECT COALESCE(SUM(bytes), 0) FROM media');
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}