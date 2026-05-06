<?php
declare(strict_types=1);

function oflc_service_schedule_last_updated_path(): string
{
    return __DIR__ . '/../service-schedule-last-updated.json';
}

function oflc_service_schedule_mark_updated(?DateTimeImmutable $updatedAt = null): void
{
    $updatedAt = $updatedAt instanceof DateTimeImmutable ? $updatedAt : new DateTimeImmutable('now');
    $payload = [
        'last_updated' => $updatedAt->format(DateTimeInterface::ATOM),
        'last_updated_date' => $updatedAt->format('Y-m-d'),
    ];

    @file_put_contents(
        oflc_service_schedule_last_updated_path(),
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX
    );
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

    return $lastUpdated->format($format);
}
