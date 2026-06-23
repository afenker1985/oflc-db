<?php
declare(strict_types=1);

function oflc_service_schedule_last_updated_path(): string
{
    return __DIR__ . '/../service-schedule-last-updated.json';
}

function oflc_service_schedule_timezone(): DateTimeZone
{
    return new DateTimeZone('America/Chicago');
}

function oflc_service_schedule_mark_updated(?DateTimeImmutable $updatedAt = null): void
{
    $updatedAt = $updatedAt instanceof DateTimeImmutable
        ? $updatedAt->setTimezone(oflc_service_schedule_timezone())
        : new DateTimeImmutable('now', oflc_service_schedule_timezone());
    $payload = [
        'last_updated' => $updatedAt->format(DateTimeInterface::ATOM),
        'last_updated_date' => $updatedAt->format('Y-m-d'),
    ];
    $path = oflc_service_schedule_last_updated_path();
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException('The service schedule last-updated marker could not be encoded.');
    }

    $bytesWritten = file_put_contents($path, $json . PHP_EOL, LOCK_EX);
    if ($bytesWritten === false) {
        $message = 'The service schedule last-updated marker could not be written to ' . $path . '.';
        error_log($message);
        throw new RuntimeException($message);
    }
}

function oflc_service_schedule_get_last_updated(): ?DateTimeImmutable
{
    $path = oflc_service_schedule_last_updated_path();
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return null;
    }

    $value = trim((string) ($payload['last_updated'] ?? $payload['last_updated_date'] ?? ''));
    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Throwable $exception) {
        return null;
    }
}

function oflc_service_schedule_format_last_updated(string $format = 'm/d/Y'): ?string
{
    $lastUpdated = oflc_service_schedule_get_last_updated();
    if (!$lastUpdated instanceof DateTimeImmutable) {
        return null;
    }

    return $lastUpdated->setTimezone(oflc_service_schedule_timezone())->format($format);
}
