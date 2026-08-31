<?php

namespace Modules\AmeiseModule\Services;

/**
 * Baut aus einem gelesenen Archiveintrag den vollständigen Zustand für einen PATCH.
 *
 * Das UpdateArchiveRequestDto verlangt isPublic und isDeleted und lässt
 * tags/metadata/contracts/contractLines auf [] defaulten. Ein PATCH, der diese
 * Felder wegließe, würde Tags und Zuordnungen leeren. Deshalb wird der Eintrag
 * immer erst gelesen und der geänderte Vollzustand gesendet.
 */
class ArchiveEntryPayload
{
    /**
     * @param array $entry   Antwort von GET .../archive-entries/{id}
     * @param array $changes Nur die Felder, die sich ändern sollen
     */
    public static function fromEntry(array $entry, array $changes = []): array
    {
        $payload = [
            'archiveType' => $entry['type'] ?? null,
            'subject' => $entry['subject'] ?? null,
            'text' => $entry['text'] ?? null,
            'tags' => array_values((array) ($entry['tags'] ?? [])),
            'metadata' => self::metadata($entry['metadata'] ?? []),
            'contracts' => self::ids($entry['contracts'] ?? []),
            'contractLines' => self::ids($entry['contractLines'] ?? []),
            'requiresReview' => (bool) ($entry['requiresReview'] ?? false),
            'isPublic' => (bool) ($entry['isPublic'] ?? false),
            'isDeleted' => (bool) ($entry['isDeleted'] ?? false),
            'date' => $entry['date'] ?? null,
            'notifyBroker' => false,
        ];

        // Leere Strings sind für subject und text nicht zulässig (minLength 1).
        foreach (['subject', 'text'] as $field) {
            if (isset($payload[$field]) && trim((string) $payload[$field]) === '') {
                $payload[$field] = null;
            }
        }

        $payload = array_merge($payload, $changes);

        // Die API verlangt Vertrags-IDs als Ganzzahlen (ContractDto.id ist
        // integer); die Oberfläche liefert Strings. Sparten-IDs bleiben Strings.
        $payload['contracts'] = self::integerIds($payload['contracts'] ?? []);
        $payload['contractLines'] = self::stringIds($payload['contractLines'] ?? []);
        $payload['tags'] = self::stringIds($payload['tags'] ?? []);

        return $payload;
    }

    /**
     * @return int[]
     */
    private static function integerIds($items): array
    {
        $ids = [];
        foreach (self::ids($items) as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids, SORT_REGULAR));
    }

    /**
     * @return string[]
     */
    private static function stringIds($items): array
    {
        $ids = [];
        foreach (self::ids($items) as $id) {
            $id = (string) $id;
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Verträge und Sparten kommen als Objekte zurück, erwartet werden nur die IDs.
     */
    private static function ids($items): array
    {
        $ids = [];
        foreach ((array) $items as $item) {
            if (is_array($item) && isset($item['id'])) {
                $ids[] = $item['id'];
            } elseif (is_scalar($item)) {
                $ids[] = $item;
            }
        }

        return $ids;
    }

    private static function metadata($items): array
    {
        $metadata = [];
        foreach ((array) $items as $item) {
            if (is_array($item) && isset($item['key'], $item['value'])) {
                $metadata[] = ['key' => $item['key'], 'value' => $item['value']];
            }
        }

        return $metadata;
    }
}
