<?php

namespace Lexa\Wp;

/**
 * Indexes/removes a single post. Used by the save hooks (via Action Scheduler),
 * by WP-CLI, and by reconcile. Decides index-vs-remove from the post's current
 * state, so trashing/unpublishing a product removes it from the index.
 */
final class Indexer
{
    public const LAST_INDEX_OPTION = 'lexa_last_index';

    public static function handle($postId): void
    {
        self::indexPost((int) $postId);
    }

    public static function indexPost(int $postId): void
    {
        $engine = EngineManager::engine();
        $post   = get_post($postId);

        $searchable = $post
            && $post->post_status === 'publish'
            && in_array($post->post_type, Settings::postTypes(), true);

        if ($searchable) {
            $engine->index(DocumentFactory::fromPost($post));
        } else {
            $engine->delete($postId); // gone / unpublished / not a searchable type
        }
        $engine->flush();
        update_option(self::LAST_INDEX_OPTION, time());
    }
}
