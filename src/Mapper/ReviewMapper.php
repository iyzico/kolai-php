<?php

declare(strict_types=1);

namespace Kolai\Mapper;

/**
 * Maps a neutral review array into the camelCase DTO Kolai expects.
 *
 * Input keys: id, product_id, rating, author, content, date, status,
 * verified_buyer, parent_id.
 *
 * Author e-mail, IP and user agent are absent on purpose: the module must not
 * collect them, so there is nothing here that could leak them.
 */
final class ReviewMapper
{
    /**
     * @param array<string,mixed> $review
     * @return array<string,mixed>|null
     */
    public function map(array $review): ?array
    {
        if ($review === []) {
            return null;
        }

        $mapped = [];

        if (isset($review['id'])) {
            $mapped['id'] = (int) $review['id'];
        }
        if (isset($review['product_id'])) {
            $mapped['productId'] = (string) $review['product_id'];
        }
        // array_key_exists, not isset: an explicit null rating is meaningful
        // (a reply carries no rating) and must survive as null, not vanish.
        if (array_key_exists('rating', $review)) {
            $mapped['rating'] = $review['rating'] !== null ? (int) $review['rating'] : null;
        }
        if (isset($review['author'])) {
            $mapped['author'] = (string) $review['author'];
        }
        if (isset($review['content'])) {
            $mapped['content'] = (string) $review['content'];
        }
        if (array_key_exists('date', $review)) {
            $mapped['date'] = $review['date'];
        }
        if (isset($review['status'])) {
            $mapped['status'] = (string) $review['status'];
        }
        if (array_key_exists('verified_buyer', $review)) {
            $mapped['verifiedBuyer'] = (bool) $review['verified_buyer'];
        }
        if (!empty($review['parent_id'])) {
            $mapped['parentId'] = (int) $review['parent_id'];
        }

        return $mapped;
    }

    /**
     * @param array<int,mixed> $reviews
     * @return array<int,array<string,mixed>>
     */
    public function mapMany(array $reviews): array
    {
        $out = [];
        foreach ($reviews as $review) {
            $mapped = is_array($review) ? $this->map($review) : null;
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }
}
