<?php

namespace Lexa\Wp\Admin;

use Lexa\Wp\DocumentFactory;
use Lexa\Wp\EngineManager;
use Lexa\Wp\Settings;

/**
 * Admin UI: "Lexa Search → Indexing / Settings".
 *
 * P2 ships a functional shell: index status, a browser-driven batch rebuild
 * (AJAX loop, so it survives the 1,783-product build without a timeout), and
 * settings. The richer status dashboard, queue health, auto-index hooks and
 * shadow-swap land in P3.
 */
final class AdminPage
{
    public const SLUG = 'lexa-search';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('wp_ajax_lexa_index_batch', [self::class, 'ajaxIndexBatch']);
        add_action('wp_ajax_lexa_drain', [self::class, 'ajaxDrain']);
    }

    public static function menu(): void
    {
        add_menu_page('Lexa Search', 'Lexa Search', 'manage_options', self::SLUG, [self::class, 'renderIndexing'], 'dashicons-search', 58);
        add_submenu_page(self::SLUG, 'Indexing', 'Indexing', 'manage_options', self::SLUG, [self::class, 'renderIndexing']);
        add_submenu_page(self::SLUG, 'Settings', 'Settings', 'manage_options', self::SLUG . '-settings', [self::class, 'renderSettings']);
    }

    public static function registerSettings(): void
    {
        register_setting('lexa_settings_group', Settings::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [Settings::class, 'sanitize'],
        ]);
    }

    public static function renderIndexing(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $stats      = EngineManager::engine()->stats();
        $indexed    = (int) $stats['doc_count'];
        $total      = EngineManager::totalCandidates();
        $nonce      = wp_create_nonce('lexa_index');
        $drainNonce = wp_create_nonce('lexa_drain');
        $cli        = 'wp lexa index';
        $pending    = \Lexa\Wp\Drainer::pendingCount();
        $lastDrain  = \Lexa\Wp\Drainer::lastDrainAt();
        $stalled    = \Lexa\Wp\Drainer::isStalled();
        // The index being built is only half the story — the kill switch has to
        // be on too, or the front end is still served by WordPress's LIKE search.
        // Reporting on lexa_ready alone showed a green "using engine" tile on
        // exactly the sites where search was silently off.
        $indexReady = (bool) get_option(\Lexa\Wp\QueryIntegration::READY_OPTION);
        $switchOn   = Settings::isEnabled();
        $ready      = $indexReady && $switchOn;
        ?>
        <div class="wrap">
            <h1>Lexa Search &rarr; Indexing</h1>
            <p>Search engine: <code>MySQL inverted-index + BM25F</code>. Khi index sẵn sàng và công tắc đang bật, engine này phục vụ luôn tìm kiếm sản phẩm ở front-end.</p>

            <?php if ($stalled) : ?>
            <div class="notice notice-error" style="padding:10px 14px;">
                <strong>Hàng đợi index chưa được xử lý</strong> — <?php echo esc_html((string) $pending); ?> mục đang chờ và không drain gần đây.
                Bấm <em>Process pending now</em>, hoặc đặt cron máy chủ: <code style="user-select:all;">*/5 * * * * <?php echo esc_html($cli); ?> &amp;&amp; wp lexa run</code>
            </div>
            <?php elseif (!$indexReady) : ?>
            <div class="notice notice-warning" style="padding:10px 14px;">
                Chưa sẵn sàng phục vụ front-end — bấm <em>Build / rebuild index</em> để bật.
            </div>
            <?php elseif (!$switchOn) : ?>
            <div class="notice notice-warning" style="padding:10px 14px;">
                Index đã sẵn sàng nhưng <strong>tìm kiếm front-end đang TẮT</strong> — site vẫn dùng tìm kiếm mặc định của WordPress.
                Bật lại ở <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '-settings')); ?>">Settings &rarr; Front-end search</a>.
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:14px;margin:1rem 0;flex-wrap:wrap;">
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:14px 18px;">
                    <div style="color:#646970;font-size:13px;">Indexed docs</div>
                    <div style="font-size:24px;font-weight:500;" id="lexa-indexed"><?php echo esc_html((string) $indexed); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:14px 18px;">
                    <div style="color:#646970;font-size:13px;">Candidate posts</div>
                    <div style="font-size:24px;font-weight:500;"><?php echo esc_html((string) $total); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:14px 18px;">
                    <div style="color:#646970;font-size:13px;">Pending jobs</div>
                    <div style="font-size:24px;font-weight:500;color:<?php echo $stalled ? '#d63638' : '#1d2327'; ?>;" id="lexa-pending"><?php echo esc_html((string) $pending); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:14px 18px;">
                    <div style="color:#646970;font-size:13px;">Front-end</div>
                    <div style="font-size:18px;font-weight:500;margin-top:5px;color:<?php echo $ready ? '#00a32a' : ($indexReady ? '#d63638' : '#646970'); ?>;">
                        <?php echo $ready ? 'dùng engine' : ($indexReady ? 'đã TẮT' : 'chưa sẵn sàng'); ?>
                    </div>
                </div>
            </div>
            <p class="description">Last drain: <?php echo $lastDrain ? esc_html(human_time_diff($lastDrain) . ' trước') : 'chưa bao giờ'; ?>. Auto-index khi thêm/sửa sản phẩm: <strong>bật</strong> (Action Scheduler).</p>
            <?php
            // Surfaced here because the effect (result ordering) is invisible on
            // this screen — without it there is no way to confirm from the
            // dashboard that the freshness setting actually took.
            $recency    = Settings::recencyMode();
            $recencyLbl = [
                'off'    => 'tắt — xếp thuần theo độ liên quan',
                'light'  => 'nhẹ',
                'medium' => 'vừa',
                'strong' => 'mạnh',
                'date'   => 'sắp thuần theo ngày',
            ];
            ?>
            <p class="description">
                Ưu tiên sản phẩm mới: <strong><?php echo esc_html($recencyLbl[$recency] ?? $recency); ?></strong><?php
                if ($recency !== 'off') {
                    echo ' — theo ' . (Settings::recencyColumn() === 'post_modified_gmt' ? 'ngày cập nhật' : 'ngày tạo');
                    if ($recency !== 'date') {
                        echo ', half-life ' . esc_html((string) Settings::recencyHalfLifeDays()) . ' ngày';
                    }
                }
                ?>. <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '-settings')); ?>">Đổi</a>
            </p>

            <p>
                <button class="button button-primary" id="lexa-rebuild">Build / rebuild index</button>
                <button class="button" id="lexa-drain">Process pending now</button>
                <span id="lexa-progress" style="margin-left:12px;color:#646970;"></span>
            </p>
            <p class="description">Catalog lớn: chạy <code><?php echo esc_html($cli); ?></code> trên server (không timeout). WP-Cron tắt → ưu tiên cron máy chủ <code>wp lexa run</code> cho auto-index chạy không cần người.</p>

            <script>
            (function(){
                var btn = document.getElementById('lexa-rebuild');
                var out = document.getElementById('lexa-progress');
                var counter = document.getElementById('lexa-indexed');
                if (!btn) return;
                btn.addEventListener('click', function(){
                    btn.disabled = true;
                    var offset = 0, total = 0;
                    function step(){
                        var body = new URLSearchParams();
                        body.set('action', 'lexa_index_batch');
                        body.set('_ajax_nonce', '<?php echo esc_js($nonce); ?>');
                        body.set('offset', offset);
                        fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:body})
                          .then(function(r){return r.json();})
                          .then(function(d){
                            if(!d || !d.success){ out.textContent = 'Lỗi: ' + (d && d.data ? d.data : 'unknown'); btn.disabled=false; return; }
                            offset = d.data.next; total = d.data.total;
                            counter.textContent = d.data.next;
                            out.textContent = d.data.next + ' / ' + total;
                            if(d.data.done){ out.textContent = 'Xong: ' + total + ' docs'; btn.disabled=false; }
                            else { step(); }
                          })
                          .catch(function(e){ out.textContent='Lỗi mạng'; btn.disabled=false; });
                    }
                    step();
                });
            })();

            (function(){
                var db = document.getElementById('lexa-drain');
                var pe = document.getElementById('lexa-pending');
                var out = document.getElementById('lexa-progress');
                if (!db) return;
                db.addEventListener('click', function(){
                    db.disabled = true;
                    function step(){
                        var body = new URLSearchParams();
                        body.set('action', 'lexa_drain');
                        body.set('_ajax_nonce', '<?php echo esc_js($drainNonce); ?>');
                        fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:body})
                          .then(function(r){return r.json();})
                          .then(function(d){
                            if(!d || !d.success){ out.textContent='Lỗi drain'; db.disabled=false; return; }
                            if(pe) pe.textContent = d.data.pending;
                            out.textContent = 'Đã xử lý ' + d.data.drained + ', còn ' + d.data.pending + ' chờ';
                            if(d.data.pending > 0 && d.data.drained > 0){ step(); } else { db.disabled=false; }
                          })
                          .catch(function(){ out.textContent='Lỗi mạng'; db.disabled=false; });
                    }
                    step();
                });
            })();
            </script>
        </div>
        <?php
    }

    public static function renderSettings(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = Settings::get();
        $types    = get_post_types(['public' => true], 'objects');
        ?>
        <div class="wrap">
            <h1>Lexa Search &rarr; Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('lexa_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Front-end search</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[enabled]"
                                    value="1" <?php checked(!empty($settings['enabled'])); ?> >
                                Let Lexa handle product search
                            </label>
                            <p class="description">Unchecked falls back to the site&rsquo;s built-in search. This box must stay on the form &mdash; an absent checkbox counts as &ldquo;off&rdquo; when settings are saved.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Newest products first</th>
                        <td>
                            <?php
                            $mode  = $settings['recency_mode'] ?? 'off';
                            $modes = [
                                'off'    => 'Off — pure relevance (BM25F)',
                                'light'  => 'Light — small nudge for new products',
                                'medium' => 'Medium — recommended',
                                'strong' => 'Strong — new products dominate unless the match is much worse',
                                'date'   => 'Newest first — sort matches strictly by date, ignoring relevance',
                            ];
                            ?>
                            <select name="<?php echo esc_attr(Settings::OPTION); ?>[recency_mode]">
                                <?php foreach ($modes as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($mode, $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Applies to both the search results page and the autocomplete API.
                                With &ldquo;Newest first&rdquo; a weak match posted yesterday outranks a perfect match from last year &mdash; the other modes keep relevance in charge.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Date to use</th>
                        <td>
                            <?php $basis = $settings['recency_basis'] ?? 'created'; ?>
                            <label style="display:block;margin-bottom:4px;">
                                <input type="radio" name="<?php echo esc_attr(Settings::OPTION); ?>[recency_basis]"
                                    value="created" <?php checked($basis, 'created'); ?> >
                                Date created (<code>post_date</code>) &mdash; when the product was first published
                            </label>
                            <label style="display:block;">
                                <input type="radio" name="<?php echo esc_attr(Settings::OPTION); ?>[recency_basis]"
                                    value="modified" <?php checked($basis, 'modified'); ?> >
                                Date modified (<code>post_modified</code>) &mdash; when it was last edited
                            </label>
                            <p class="description">
                                <strong>Careful with &ldquo;date modified&rdquo;:</strong> WooCommerce bumps it on any edit, including
                                price and stock changes and bulk edits &mdash; so an old product can jump to the top of search
                                results just because its stock level changed.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Half-life</th>
                        <td>
                            <input type="number" min="1" max="3650" step="1" class="small-text"
                                name="<?php echo esc_attr(Settings::OPTION); ?>[recency_halflife]"
                                value="<?php echo esc_attr((int) ($settings['recency_halflife'] ?? 180)); ?>" > days
                            <p class="description">How fast the boost fades: a product this old gets half the boost of a brand-new one. Ignored in &ldquo;Newest first&rdquo; mode.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Searchable post types</th>
                        <td>
                            <?php foreach ($types as $type) : ?>
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[post_types][]"
                                        value="<?php echo esc_attr($type->name); ?>"
                                        <?php checked(in_array($type->name, (array) $settings['post_types'], true)); ?> >
                                    <?php echo esc_html($type->labels->singular_name . ' (' . $type->name . ')'); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">Changing this requires a rebuild. Re-run the indexer afterwards.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function ajaxDrain(): void
    {
        check_ajax_referer('lexa_drain');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        $drained = \Lexa\Wp\Drainer::run(200);
        wp_send_json_success([
            'drained' => $drained,
            'pending' => \Lexa\Wp\Drainer::pendingCount(),
        ]);
    }

    public static function ajaxIndexBatch(): void
    {
        check_ajax_referer('lexa_index');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $batch  = 50;

        $ids = get_posts([
            'post_type'      => Settings::postTypes(),
            'post_status'    => 'publish',
            'posts_per_page' => $batch,
            'offset'         => $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $engine = EngineManager::engine();
        foreach ($ids as $id) {
            $post = get_post($id);
            if ($post) {
                $engine->index(DocumentFactory::fromPost($post));
            }
        }
        $engine->flush();

        $count = count($ids);
        $done  = $count < $batch;
        if ($done) {
            update_option(\Lexa\Wp\QueryIntegration::READY_OPTION, 1); // build complete → engine may serve front-end
        }
        wp_send_json_success([
            'indexed' => $count,
            'next'    => $offset + $count,
            'total'   => EngineManager::totalCandidates(),
            'done'    => $done,
        ]);
    }
}
