<?php
namespace Arshline\Hosha2;

/**
 * Minimal abstraction for form retrieval used by generate pipeline.
 * Returning associative array representing current form state (if needed for future edits).
 */
interface Hosha2FormRepositoryInterface
{
    /**
     * Find a form by its id.
     * @param int $id
     * @return array|null returns associative form data or null if not found
     */
    public function findById(int $id): ?array;
}
?>