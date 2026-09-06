<?php
/**
 * Thin wrapper over the two Shopify APIs this plugin uses:
 *   - Storefront GraphQL (Cart API, product lookups) with the Storefront token
 *   - Admin REST (order poll, product push) with the Admin token
 *
 * Every method returns plain arrays / WP_Error so callers never touch transport
 * details. Product lookups are cached in transients to keep page renders fast.
 */

if (!defined('ABSPATH')) { exit; }

class WPSB_Shopify_API {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function domain() {
        return get_option('wpsb_shop_domain', '');
    }

    public function is_configured() {
        return $this->domain() && get_option('wpsb_storefront_token', '');
    }

    public function has_admin() {
        return $this->domain() && get_option('wpsb_admin_token', '');
    }

    /* ------------------------------------------------------------------ */
    /* Storefront GraphQL                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Execute a Storefront GraphQL query.
     *
     * @return array|WP_Error decoded `data` array, or WP_Error on transport /
     *                        GraphQL errors.
     */
    public function graphql($query, $variables = []) {
        $domain = $this->domain();
        $token  = get_option('wpsb_storefront_token', '');
        if (!$domain || !$token) {
            return new WP_Error('wpsb_not_configured', __('Shopify Storefront token not configured.', 'wpsb'));
        }

        $endpoint = sprintf('https://%s/api/%s/graphql.json', $domain, WPSB_API_VERSION);
        $response = wp_remote_post($endpoint, [
            'timeout' => 20,
            'headers' => [
                'Content-Type'                      => 'application/json',
                'X-Shopify-Storefront-Access-Token' => $token,
            ],
            'body' => wp_json_encode(['query' => $query, 'variables' => (object) $variables]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            return new WP_Error('wpsb_http_' . $code, sprintf(__('Shopify Storefront returned HTTP %d.', 'wpsb'), $code));
        }
        if (!empty($body['errors'])) {
            $msg = isset($body['errors'][0]['message']) ? $body['errors'][0]['message'] : __('Unknown GraphQL error.', 'wpsb');
            return new WP_Error('wpsb_graphql', $msg, $body['errors']);
        }
        return isset($body['data']) ? $body['data'] : [];
    }

    /**
     * Fetch a product by handle. Result (including "not found") is cached in a
     * transient; TTL is filterable and busted on (re)import.
     *
     * @return array|null normalized product array, or null when missing.
     */
    public function get_product_by_handle($handle) {
        $handle = sanitize_title($handle);
        if (!$handle) {
            return null;
        }
        $cache_key = 'wpsb_ph_' . md5($this->domain() . '|' . $handle);
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            return $cached === 'NONE' ? null : $cached;
        }

        $query = 'query($handle: String!) {
            productByHandle(handle: $handle) {
                id title handle description descriptionHtml
                featuredImage { url altText }
                priceRange { minVariantPrice { amount currencyCode } }
                variants(first: 100) {
                    edges { node {
                        id title sku availableForSale
                        price { amount currencyCode }
                        compareAtPrice { amount currencyCode }
                    } }
                }
            }
        }';
        $data = $this->graphql($query, ['handle' => $handle]);

        $ttl = (int) apply_filters('wpsb_product_cache_ttl', 15 * MINUTE_IN_SECONDS, $handle);
        if (is_wp_error($data) || empty($data['productByHandle'])) {
            // Cache the "missing" answer briefly so a bad handle doesn't hammer
            // the API on every page render.
            set_transient($cache_key, 'NONE', MINUTE_IN_SECONDS * 2);
            return null;
        }
        $product = $this->normalize_product($data['productByHandle']);
        set_transient($cache_key, $product, $ttl);
        return $product;
    }

    /**
     * Find a variant by SKU via the Storefront product search (v1.11.3 SKU
     * fallback). Returns the matching product with the SKU-carrying variant
     * pre-selected, or null.
     */
    public function get_variant_by_sku($sku) {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }
        $query = 'query($q: String!) {
            products(first: 5, query: $q) {
                edges { node {
                    id title handle
                    featuredImage { url altText }
                    variants(first: 100) {
                        edges { node {
                            id title sku availableForSale
                            price { amount currencyCode }
                            compareAtPrice { amount currencyCode }
                        } }
                    }
                } }
            }
        }';
        $data = $this->graphql($query, ['q' => 'sku:' . $sku]);
        if (is_wp_error($data) || empty($data['products']['edges'])) {
            return null;
        }
        foreach ($data['products']['edges'] as $edge) {
            $product = $this->normalize_product($edge['node']);
            foreach ($product['variants'] as $variant) {
                if (isset($variant['sku']) && strcasecmp($variant['sku'], $sku) === 0) {
                    $product['matched_variant'] = $variant;
                    return $product;
                }
            }
        }
        return null;
    }

    /**
     * Reliable SKU → variant lookup via the Admin GraphQL API. The Admin API
     * supports `sku:` search directly (the Storefront search does not, on all
     * plans/versions), and it finds variants regardless of channel publication,
     * so it is the preferred linker path whenever an Admin token is present.
     * Requires the Admin token to include read_products.
     *
     * Returns the same shape as get_variant_by_sku() (a product array with a
     * `matched_variant`), or null.
     */
    public function admin_variant_by_sku($sku) {
        $sku = trim((string) $sku);
        if ($sku === '' || !$this->has_admin()) {
            return null;
        }
        $query = 'query($q: String!) {
            productVariants(first: 10, query: $q) {
                edges { node {
                    id sku title availableForSale price
                    product { id handle title featuredImage { url altText } }
                } }
            }
        }';
        $data = $this->admin_graphql($query, ['q' => 'sku:' . $sku]);
        if (is_wp_error($data) || empty($data['productVariants']['edges'])) {
            return null;
        }
        foreach ($data['productVariants']['edges'] as $edge) {
            $node = $edge['node'];
            if (empty($node['sku']) || strcasecmp($node['sku'], $sku) !== 0) {
                continue;
            }
            $variant = [
                'id'        => $node['id'],
                'title'     => $node['title'] ?? '',
                'sku'       => $node['sku'],
                'available' => !empty($node['availableForSale']),
                'price'     => isset($node['price']) ? (float) $node['price'] : null,
                'currency'  => get_option('wpsb_currency', 'USD'),
                'compare_at'=> null,
            ];
            return [
                'id'              => $node['product']['id'] ?? '',
                'title'           => $node['product']['title'] ?? '',
                'handle'          => $node['product']['handle'] ?? '',
                'image'           => $node['product']['featuredImage']['url'] ?? '',
                'image_alt'       => $node['product']['featuredImage']['altText'] ?? '',
                'variants'        => [$variant],
                'default_variant' => $variant['id'],
                'matched_variant' => $variant,
            ];
        }
        return null;
    }

    /**
     * Execute an Admin GraphQL query with the Admin token.
     *
     * @return array|WP_Error decoded `data`.
     */
    public function admin_graphql($query, $variables = []) {
        $domain = $this->domain();
        $token  = get_option('wpsb_admin_token', '');
        if (!$domain || !$token) {
            return new WP_Error('wpsb_no_admin', __('Shopify Admin token not configured.', 'wpsb'));
        }
        $endpoint = sprintf('https://%s/admin/api/%s/graphql.json', $domain, WPSB_API_VERSION);
        $response = wp_remote_post($endpoint, [
            'timeout' => 25,
            'headers' => [
                'Content-Type'           => 'application/json',
                'X-Shopify-Access-Token' => $token,
            ],
            'body' => wp_json_encode(['query' => $query, 'variables' => (object) $variables]),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('wpsb_admin_gql_' . $code, sprintf(__('Admin GraphQL HTTP %d.', 'wpsb'), $code));
        }
        if (!empty($body['errors'])) {
            $msg = $body['errors'][0]['message'] ?? __('Admin GraphQL error.', 'wpsb');
            return new WP_Error('wpsb_admin_gql', $msg);
        }
        return $body['data'] ?? [];
    }

    /**
     * List catalogue products (paged) for the admin Shopify Products browser.
     *
     * @return array{products:array,cursor:?string}|WP_Error
     */
    public function list_products($cursor = null, $first = 20) {
        $query = 'query($first: Int!, $after: String) {
            products(first: $first, after: $after, sortKey: TITLE) {
                pageInfo { hasNextPage endCursor }
                edges { node {
                    id title handle
                    featuredImage { url altText }
                    priceRange { minVariantPrice { amount currencyCode } }
                    variants(first: 100) {
                        edges { node { id title sku availableForSale price { amount currencyCode } } }
                    }
                } }
            }
        }';
        $data = $this->graphql($query, ['first' => (int) $first, 'after' => $cursor]);
        if (is_wp_error($data)) {
            return $data;
        }
        $out = ['products' => [], 'cursor' => null];
        if (!empty($data['products']['edges'])) {
            foreach ($data['products']['edges'] as $edge) {
                $out['products'][] = $this->normalize_product($edge['node']);
            }
        }
        if (!empty($data['products']['pageInfo']['hasNextPage'])) {
            $out['cursor'] = $data['products']['pageInfo']['endCursor'];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Cart API — build a hosted checkout from line items                  */
    /* ------------------------------------------------------------------ */

    /**
     * Create a Shopify cart from [ ['variantId'=>gid, 'quantity'=>n], ... ] and
     * return its hosted checkout URL. Buyer email, cart attributes and note are
     * optional; attributes carry attribution + return-to-WordPress.
     *
     * @return string|WP_Error checkoutUrl
     */
    public function create_checkout($lines, $args = []) {
        $lines = array_values(array_filter(array_map(function ($l) {
            if (empty($l['variantId'])) {
                return null;
            }
            return [
                'merchandiseId' => $l['variantId'],
                'quantity'      => max(1, (int) ($l['quantity'] ?? 1)),
            ];
        }, (array) $lines)));

        if (!$lines) {
            return new WP_Error('wpsb_empty_cart', __('No valid line items to check out.', 'wpsb'));
        }

        $input = ['lines' => $lines];

        if (!empty($args['email'])) {
            $input['buyerIdentity'] = ['email' => sanitize_email($args['email'])];
        }
        if (!empty($args['attributes']) && is_array($args['attributes'])) {
            $attrs = [];
            foreach ($args['attributes'] as $k => $v) {
                $attrs[] = ['key' => (string) $k, 'value' => (string) $v];
            }
            $input['attributes'] = $attrs;
        }
        if (!empty($args['note'])) {
            $input['note'] = (string) $args['note'];
        }

        $mutation = 'mutation($input: CartInput!) {
            cartCreate(input: $input) {
                cart { id checkoutUrl }
                userErrors { field message }
            }
        }';
        $data = $this->graphql($mutation, ['input' => $input]);
        if (is_wp_error($data)) {
            return $data;
        }
        if (!empty($data['cartCreate']['userErrors'])) {
            $msg = $data['cartCreate']['userErrors'][0]['message'];
            return new WP_Error('wpsb_cart_error', $msg);
        }
        $url = $data['cartCreate']['cart']['checkoutUrl'] ?? '';
        if (!$url) {
            return new WP_Error('wpsb_cart_error', __('Shopify did not return a checkout URL.', 'wpsb'));
        }
        return $url;
    }

    /* ------------------------------------------------------------------ */
    /* Admin REST                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Perform an Admin REST request. $query is added to GET requests with
     * proper URL-encoding (fixes the ISO-timestamp order-poll bug from v1.7).
     *
     * @return array|WP_Error decoded JSON body.
     */
    public function admin_rest($method, $path, $query = [], $body = null) {
        $domain = $this->domain();
        $token  = get_option('wpsb_admin_token', '');
        if (!$domain || !$token) {
            return new WP_Error('wpsb_no_admin', __('Shopify Admin token not configured.', 'wpsb'));
        }
        $url = sprintf('https://%s/admin/api/%s/%s', $domain, WPSB_API_VERSION, ltrim($path, '/'));
        if (!empty($query)) {
            $url = add_query_arg(array_map('rawurlencode', $query), $url);
        }
        $args = [
            'method'  => strtoupper($method),
            'timeout' => 25,
            'headers' => [
                'X-Shopify-Access-Token' => $token,
                'Content-Type'           => 'application/json',
            ],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $msg = isset($data['errors']) ? (is_string($data['errors']) ? $data['errors'] : wp_json_encode($data['errors'])) : sprintf(__('Admin API HTTP %d.', 'wpsb'), $code);
            return new WP_Error('wpsb_admin_http_' . $code, $msg);
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Poll recent orders since an ISO-8601 timestamp. Used by the order cron to
     * detect completed checkouts without webhooks.
     */
    public function get_orders_since($iso_timestamp) {
        return $this->admin_rest('GET', 'orders.json', [
            'status'          => 'any',
            'created_at_min'  => $iso_timestamp,
            'limit'           => '50',
            'fields'          => 'id,total_price,currency,note_attributes,created_at,email,financial_status',
        ]);
    }

    /**
     * Push a product to Shopify (Admin, needs write_products). Returns the
     * created product array so the caller can link variant IDs back.
     */
    public function push_product($payload) {
        return $this->admin_rest('POST', 'products.json', [], ['product' => $payload]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Flatten a Storefront product node into the internal shape the rest of the
     * plugin uses (variants as a plain list; first available variant is default).
     */
    private function normalize_product($node) {
        $variants = [];
        if (!empty($node['variants']['edges'])) {
            foreach ($node['variants']['edges'] as $v) {
                $vn = $v['node'];
                $variants[] = [
                    'id'        => $vn['id'] ?? '',
                    'title'     => $vn['title'] ?? '',
                    'sku'       => $vn['sku'] ?? '',
                    'available' => !empty($vn['availableForSale']),
                    'price'     => isset($vn['price']['amount']) ? (float) $vn['price']['amount'] : null,
                    'currency'  => $vn['price']['currencyCode'] ?? get_option('wpsb_currency', 'USD'),
                    'compare_at'=> isset($vn['compareAtPrice']['amount']) ? (float) $vn['compareAtPrice']['amount'] : null,
                ];
            }
        }
        return [
            'id'          => $node['id'] ?? '',
            'title'       => $node['title'] ?? '',
            'handle'      => $node['handle'] ?? '',
            'description' => $node['description'] ?? '',
            'description_html' => $node['descriptionHtml'] ?? '',
            'image'       => $node['featuredImage']['url'] ?? '',
            'image_alt'   => $node['featuredImage']['altText'] ?? ($node['title'] ?? ''),
            'price'       => isset($node['priceRange']['minVariantPrice']['amount']) ? (float) $node['priceRange']['minVariantPrice']['amount'] : (isset($variants[0]['price']) ? $variants[0]['price'] : null),
            'currency'    => $node['priceRange']['minVariantPrice']['currencyCode'] ?? get_option('wpsb_currency', 'USD'),
            'variants'    => $variants,
            'default_variant' => $this->pick_default_variant($variants),
        ];
    }

    private function pick_default_variant($variants) {
        foreach ($variants as $v) {
            if (!empty($v['available'])) {
                return $v['id'];
            }
        }
        return $variants[0]['id'] ?? '';
    }
}
