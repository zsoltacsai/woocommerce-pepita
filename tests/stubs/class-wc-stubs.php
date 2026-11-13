<?php
/**
 * WooCommerce stubs: product, variation, attribute, order and tax models.
 *
 * @package WPS\Pepita\Tests
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {
		public string $version = '10.0.0';
	}
}

class WC_Product {

	/** @var array<string, mixed> */
	protected array $data = array();

	/** @var int[] */
	protected array $children = array();

	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct( array $data = array() ) {
		$this->data = array_merge(
			array(
				'id'                => 0,
				'name'              => '',
				'type'              => 'simple',
				'status'            => 'publish',
				'price'             => '',
				'regular_price'     => '',
				'sale_price'        => '',
				'description'       => '',
				'short_description' => '',
				'sku'               => '',
				'tax_class'         => '',
				'stock_status'      => 'instock',
				'manage_stock'      => false,
				'stock_quantity'    => null,
				'image_id'          => 0,
				'gallery_image_ids' => array(),
				'attributes'        => array(),
				'length'            => '',
				'width'             => '',
				'height'            => '',
				'weight'            => '',
				'parent_id'         => 0,
				'date_modified'     => null,
				'variation'         => array(),
			),
			$data
		);
	}

	public function get_id(): int {
		return (int) $this->data['id'];
	}

	public function get_name(): string {
		return (string) $this->data['name'];
	}

	public function get_type(): string {
		return (string) $this->data['type'];
	}

	public function is_type( string $type ): bool {
		if ( 'variation' === $type ) {
			return $this instanceof WC_Product_Variation;
		}

		return $this->get_type() === $type;
	}

	public function get_status(): string {
		return (string) $this->data['status'];
	}

	public function set_status( string $status ): void {
		$this->data['status'] = $status;
	}

	public function get_price(): string {
		return (string) $this->data['price'];
	}

	public function set_price( string $price ): void {
		$this->data['price'] = $price;
	}

	public function get_regular_price(): string {
		return (string) $this->data['regular_price'];
	}

	public function get_sale_price(): string {
		return (string) $this->data['sale_price'];
	}

	public function get_description(): string {
		return (string) $this->data['description'];
	}

	public function get_short_description(): string {
		return (string) $this->data['short_description'];
	}

	public function get_sku(): string {
		return (string) $this->data['sku'];
	}

	public function get_tax_class(): string {
		return (string) $this->data['tax_class'];
	}

	public function is_in_stock(): bool {
		if ( $this->managing_stock() ) {
			return (int) $this->data['stock_quantity'] > 0;
		}

		return 'instock' === (string) $this->data['stock_status'];
	}

	public function managing_stock(): bool {
		return (bool) $this->data['manage_stock'];
	}

	public function get_stock_quantity() {
		return $this->data['stock_quantity'];
	}

	public function get_image_id(): int {
		return (int) $this->data['image_id'];
	}

	/**
	 * @return int[]
	 */
	public function get_gallery_image_ids(): array {
		return array_map( 'intval', (array) $this->data['gallery_image_ids'] );
	}

	/**
	 * @return array<int, WC_Product_Attribute>
	 */
	public function get_attributes(): array {
		$out = array();

		foreach ( (array) $this->data['attributes'] as $attribute ) {
			$out[] = $attribute instanceof WC_Product_Attribute ? $attribute : new WC_Product_Attribute( (array) $attribute );
		}

		return $out;
	}

	/**
	 * @return int[]
	 */
	public function get_children(): array {
		return $this->children;
	}

	public function register_child( int $child_id ): void {
		if ( ! in_array( $child_id, $this->children, true ) ) {
			$this->children[] = $child_id;
		}
	}

	/**
	 * @return \DateTimeInterface|null
	 */
	public function get_date_modified() {
		$modified = $this->data['date_modified'];

		if ( $modified instanceof \DateTimeInterface ) {
			return $modified;
		}

		if ( is_int( $modified ) && $modified > 0 ) {
			return new \DateTimeImmutable( '@' . $modified );
		}

		return null;
	}

	public function get_length(): string {
		return (string) $this->data['length'];
	}

	public function get_width(): string {
		return (string) $this->data['width'];
	}

	public function get_height(): string {
		return (string) $this->data['height'];
	}

	public function get_weight(): string {
		return (string) $this->data['weight'];
	}

	public function get_parent_id(): int {
		return (int) $this->data['parent_id'];
	}

	public function get_permalink(): string {
		return get_permalink( $this->get_id() );
	}

	/**
	 * @return array<string, string>
	 */
	public function get_variation_attributes(): array {
		return array();
	}

	public function save(): int {
		return $this->get_id();
	}
}

class WC_Product_Variation extends WC_Product {

	public function __construct( array $data = array() ) {
		parent::__construct( $data );
		$this->data['type'] = 'variation';
	}

	/**
	 * @return array<string, string>
	 */
	public function get_variation_attributes(): array {
		return array_map( 'strval', (array) $this->data['variation'] );
	}
}

class WC_Product_Attribute {

	/** @var array<string, mixed> */
	private array $data;

	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct( array $data = array() ) {
		$this->data = array_merge(
			array(
				'name'      => '',
				'options'   => array(),
				'position'  => 0,
				'visible'   => true,
				'variation' => false,
				'taxonomy'  => false,
			),
			$data
		);
	}

	public function get_name(): string {
		return (string) $this->data['name'];
	}

	/**
	 * @return array<int, string>
	 */
	public function get_options(): array {
		return array_map( 'strval', (array) $this->data['options'] );
	}

	public function get_position(): int {
		return (int) $this->data['position'];
	}

	public function get_visible(): bool {
		return (bool) $this->data['visible'];
	}

	public function get_variation(): bool {
		return (bool) $this->data['variation'];
	}

	public function is_taxonomy(): bool {
		return (bool) $this->data['taxonomy'];
	}
}

class WC_Order_Item {

	/** @var array<string, mixed> */
	protected array $data = array();

	/** @var array<string, mixed> */
	protected array $meta = array();

	protected int $item_id = 0;

	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct( array $data = array() ) {
		$this->data = $data;
	}

	public function get_id(): int {
		return $this->item_id;
	}

	public function set_id( int $id ): void {
		$this->item_id = $id;
	}

	public function get_name(): string {
		return (string) ( $this->data['name'] ?? '' );
	}

	public function set_name( string $name ): void {
		$this->data['name'] = $name;
	}

	public function get_total(): float {
		return (float) ( $this->data['total'] ?? 0 );
	}

	public function set_total( float $total ): void {
		$this->data['total'] = $total;
	}

	public function get_quantity(): int {
		return (int) ( $this->data['quantity'] ?? 1 );
	}

	public function set_quantity( int $quantity ): void {
		$this->data['quantity'] = $quantity;
	}

	public function add_meta_data( string $key, $value, bool $unique = false ): void {
		$this->meta[ $key ] = $value;
	}

	/**
	 * @return mixed
	 */
	public function get_meta( string $key, bool $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function save(): int {
		return $this->item_id;
	}
}

class WC_Order_Item_Product extends WC_Order_Item {

	/** @var WC_Product|null */
	private ?WC_Product $product = null;

	public function set_product( WC_Product $product ): void {
		$this->product = $product;
		$this->data['product_id'] = $product->get_id();
		$this->data['name']       = $product->get_name();
	}

	public function get_product(): ?WC_Product {
		return $this->product;
	}

	public function get_product_id(): int {
		return (int) ( $this->data['product_id'] ?? 0 );
	}

	public function get_subtotal(): float {
		return (float) ( $this->data['subtotal'] ?? 0 );
	}

	public function set_subtotal( float $subtotal ): void {
		$this->data['subtotal'] = $subtotal;
	}
}

class WC_Order_Item_Shipping extends WC_Order_Item {

	public function set_method_title( string $title ): void {
		$this->data['method_title'] = $title;
		$this->data['name']         = $title;
	}

	public function get_method_title(): string {
		return (string) ( $this->data['method_title'] ?? '' );
	}

	public function set_method_id( string $id ): void {
		$this->data['method_id'] = $id;
	}

	public function get_method_id(): string {
		return (string) ( $this->data['method_id'] ?? '' );
	}
}

class WC_Order_Item_Fee extends WC_Order_Item {

	public function set_amount( float $amount ): void {
		$this->data['amount'] = $amount;
	}

	public function get_amount(): float {
		return (float) ( $this->data['amount'] ?? 0 );
	}
}

class WC_Order {

	private int $id = 0;
	private string $status = 'pending';
	private string $currency = 'HUF';
	private string $created_via = '';
	private string $payment_method = '';
	private string $payment_method_title = '';
	private int $customer_id = 0;
	private float $total = 0.0;
	private float $cart_tax = 0.0;
	private float $shipping_tax = 0.0;
	private bool $prices_include_tax = false;
	private int $date_created = 0;
	private int $date_paid = 0;

	/** @var array<string, mixed> */
	private array $meta = array();

	/** @var array<string, mixed> */
	private array $billing = array();

	/** @var array<string, mixed> */
	private array $shipping = array();

	/** @var array<int, WC_Order_Item> */
	private array $items = array();

	/** @var string[] */
	private array $notes = array();

	private int $item_sequence = 0;

	/**
	 * @param array<string, mixed> $args
	 */
	public function __construct( array $args = array() ) {
		$this->created_via = (string) ( $args['created_via'] ?? '' );
		$this->currency    = (string) ( $args['currency'] ?? WPS_Test_State::$currency );
	}

	public function get_id(): int {
		return $this->id;
	}

	public function assign_id( int $id ): void {
		$this->id = $id;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function set_status( string $status, string $note = '' ): void {
		$this->status = str_replace( 'wc-', '', $status );

		if ( '' !== $note ) {
			$this->add_order_note( $note );
		}
	}

	public function get_currency(): string {
		return $this->currency;
	}

	public function set_currency( string $currency ): void {
		$this->currency = $currency;
	}

	public function get_created_via(): string {
		return $this->created_via;
	}

	public function set_created_via( string $via ): void {
		$this->created_via = $via;
	}

	public function get_payment_method(): string {
		return $this->payment_method;
	}

	public function set_payment_method( $method ): void {
		$this->payment_method = is_object( $method ) ? (string) $method->id : (string) $method;
	}

	public function get_payment_method_title(): string {
		return $this->payment_method_title;
	}

	public function set_payment_method_title( string $title ): void {
		$this->payment_method_title = $title;
	}

	public function get_customer_id(): int {
		return $this->customer_id;
	}

	public function set_customer_id( int $id ): void {
		$this->customer_id = $id;
	}

	public function get_total(): float {
		return $this->total;
	}

	public function set_total( float $total ): void {
		$this->total = $total;
	}

	public function set_cart_tax( float $tax ): void {
		$this->cart_tax = $tax;
	}

	public function get_cart_tax(): float {
		return $this->cart_tax;
	}

	public function set_shipping_tax( float $tax ): void {
		$this->shipping_tax = $tax;
	}

	public function get_shipping_tax(): float {
		return $this->shipping_tax;
	}

	public function set_prices_include_tax( bool $include ): void {
		$this->prices_include_tax = $include;
	}

	public function get_prices_include_tax(): bool {
		return $this->prices_include_tax;
	}

	public function get_date_created() {
		return $this->date_created > 0 ? new \DateTimeImmutable( '@' . $this->date_created ) : null;
	}

	public function set_date_created( $date ): void {
		$this->date_created = $date instanceof \DateTimeInterface ? $date->getTimestamp() : (int) $date;
	}

	public function get_date_paid() {
		return $this->date_paid > 0 ? new \DateTimeImmutable( '@' . $this->date_paid ) : null;
	}

	public function set_date_paid( $date ): void {
		$this->date_paid = $date instanceof \DateTimeInterface ? $date->getTimestamp() : (int) $date;
	}

	public function update_meta_data( string $key, $value, bool $unique = false ): void {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( string $key ): void {
		unset( $this->meta[ $key ] );
	}

	/**
	 * @return mixed
	 */
	public function get_meta( string $key, bool $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_meta_data(): array {
		return $this->meta;
	}

	/* Billing / shipping accessors. */

	public function set_billing_first_name( string $value ): void {
		$this->billing['first_name'] = $value;
	}

	public function set_billing_last_name( string $value ): void {
		$this->billing['last_name'] = $value;
	}

	public function set_billing_email( string $value ): void {
		$this->billing['email'] = $value;
	}

	public function set_billing_phone( string $value ): void {
		$this->billing['phone'] = $value;
	}

	public function set_billing_company( string $value ): void {
		$this->billing['company'] = $value;
	}

	public function set_billing_country( string $value ): void {
		$this->billing['country'] = $value;
	}

	public function set_billing_city( string $value ): void {
		$this->billing['city'] = $value;
	}

	public function set_billing_postcode( string $value ): void {
		$this->billing['postcode'] = $value;
	}

	public function set_billing_address_1( string $value ): void {
		$this->billing['address_1'] = $value;
	}

	public function set_billing_address_2( string $value ): void {
		$this->billing['address_2'] = $value;
	}

	public function set_shipping_first_name( string $value ): void {
		$this->shipping['first_name'] = $value;
	}

	public function set_shipping_last_name( string $value ): void {
		$this->shipping['last_name'] = $value;
	}

	public function set_shipping_country( string $value ): void {
		$this->shipping['country'] = $value;
	}

	public function set_shipping_city( string $value ): void {
		$this->shipping['city'] = $value;
	}

	public function set_shipping_postcode( string $value ): void {
		$this->shipping['postcode'] = $value;
	}

	public function set_shipping_address_1( string $value ): void {
		$this->shipping['address_1'] = $value;
	}

	public function set_shipping_address_2( string $value ): void {
		$this->shipping['address_2'] = $value;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_billing(): array {
		return $this->billing;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_shipping(): array {
		return $this->shipping;
	}

	/**
	 * @return mixed
	 */
	public function get_billing_email() {
		return $this->billing['email'] ?? '';
	}

	/**
	 * @param array<string, mixed> $args
	 * @return int|false
	 */
	public function add_product( WC_Product $product, int $quantity = 1, array $args = array() ) {
		$item = new WC_Order_Item_Product();
		$item->set_id( ++$this->item_sequence );
		$item->set_product( $product );
		$item->set_quantity( $quantity );
		$item->set_total( (float) ( $args['total'] ?? 0 ) );
		$item->set_subtotal( (float) ( $args['subtotal'] ?? $args['total'] ?? 0 ) );

		if ( isset( $args['name'] ) ) {
			$item->set_name( (string) $args['name'] );
		}

		$this->items[ $item->get_id() ] = $item;

		return $item->get_id();
	}

	public function add_item( WC_Order_Item $item ): int {
		if ( 0 === $item->get_id() ) {
			$item->set_id( ++$this->item_sequence );
		}

		$this->items[ $item->get_id() ] = $item;

		return $item->get_id();
	}

	/**
	 * @return WC_Order_Item|null
	 */
	public function get_item( int $item_id ) {
		return $this->items[ $item_id ] ?? null;
	}

	/**
	 * @return array<int, WC_Order_Item>
	 */
	public function get_items( string $type = '' ): array {
		if ( '' === $type ) {
			return $this->items;
		}

		$map = array(
			'line_item' => WC_Order_Item_Product::class,
			'shipping'  => WC_Order_Item_Shipping::class,
			'fee'       => WC_Order_Item_Fee::class,
		);

		$class = $map[ $type ] ?? '';

		if ( '' === $class ) {
			return array();
		}

		return array_values( array_filter( $this->items, static fn( $item ): bool => $item instanceof $class ) );
	}

	/**
	 * @return array<int, WC_Order_Item_Shipping>
	 */
	public function get_shipping_methods(): array {
		return $this->get_items( 'shipping' );
	}

	public function calculate_totals( bool $and_taxes = true ): void {
		$total = 0.0;

		foreach ( $this->items as $item ) {
			if ( $item instanceof WC_Order_Item_Shipping ) {
				$total += $item->get_total();
				continue;
			}

			if ( $item instanceof WC_Order_Item_Fee ) {
				$total += $item->get_total();
				continue;
			}

			$total += $item->get_total();
		}

		$this->total = round( $total, 2 );
	}

	public function add_order_note( string $note, int $is_customer_note = 0, bool $added_by_user = false ): int {
		$this->notes[] = $note;

		return count( $this->notes );
	}

	/**
	 * @return string[]
	 */
	public function get_customer_notes(): array {
		return $this->notes;
	}

	public function save(): int {
		WPS_Test_State::$last_save_email_enabled = (bool) apply_filters( 'woocommerce_email_enabled_new_order', true );

		if ( WPS_Test_State::$fail_order_save ) {
			return 0;
		}

		$saved = WPS_Test_State::add_order( $this );

		return $saved->get_id();
	}
}

class WC_Tax {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_rates( string $tax_class = '', $customer = null ): array {
		$key   = '' === $tax_class ? '__standard__' : $tax_class;
		$rates = WPS_Test_State::$tax_rates[ $key ] ?? array();
		$out   = array();

		foreach ( $rates as $index => $rate ) {
			$out[ 'rate-' . $index ] = array(
				'rate'     => (float) $rate['rate'],
				'label'    => '',
				'shipping' => 'yes',
				'compound' => 'no',
			);
		}

		return $out;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_base_tax_rates( string $tax_class = '' ): array {
		return self::get_rates( $tax_class );
	}
}

/* -------------------------------------------------------------------------
 * WooCommerce functions
 * ---------------------------------------------------------------------- */

/**
 * @return WC_Product|false
 */
function wc_get_product( $product_id ) {
	$id = is_object( $product_id ) ? (int) ( $product_id->ID ?? 0 ) : (int) $product_id;

	return WPS_Test_State::$products[ $id ] ?? false;
}

/**
 * @param array<string, mixed> $args
 * @return array<int, int|WC_Product>|object
 */
function wc_get_products( array $args = array() ) {
	$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : -1;
	$page   = max( 1, (int) ( $args['page'] ?? 1 ) );
	$return = (string) ( $args['return'] ?? 'objects' );
	$status = $args['status'] ?? array( 'publish' );
	$status = is_array( $status ) ? $status : array( $status );

	$ids = array_keys( WPS_Test_State::$products );
	sort( $ids );

	$ids = array_values(
		array_filter(
			$ids,
			static function ( int $id ) use ( $status ): bool {
				$product = WPS_Test_State::$products[ $id ];

				if ( $product instanceof WC_Product_Variation ) {
					return false;
				}

				return in_array( $product->get_status(), $status, true );
			}
		)
	);

	if ( isset( $args['category'] ) ) {
		$terms = array_map( 'intval', (array) $args['category'] );

		$ids = array_values(
			array_filter(
				$ids,
				static function ( int $id ) use ( $terms ): bool {
					$assigned = WPS_Test_State::$post_terms[ $id ][ 'product_cat' ] ?? array();

					return array() !== array_intersect( $assigned, $terms );
				}
			)
		);
	}

	if ( isset( $args['type'] ) ) {
		$types = array_map( 'strval', (array) $args['type'] );

		$ids = array_values(
			array_filter(
				$ids,
				static fn( int $id ): bool => in_array( WPS_Test_State::$products[ $id ]->get_type(), $types, true )
			)
		);
	}

	if ( isset( $args['stock_status'] ) ) {
		$stock = (string) $args['stock_status'];

		$ids = array_values(
			array_filter(
				$ids,
				static function ( int $id ) use ( $stock ): bool {
					$product = WPS_Test_State::$products[ $id ];

					return $product->is_in_stock() === ( 'instock' === $stock );
				}
			)
		);
	}

	if ( '' !== (string) ( $args['search'] ?? '' ) ) {
		$search = (string) $args['search'];

		$ids = array_values(
			array_filter(
				$ids,
				static function ( int $id ) use ( $search ): bool {
					$product = WPS_Test_State::$products[ $id ];

					return false !== stripos( $product->get_name(), $search )
						|| false !== stripos( $product->get_sku(), $search );
				}
			)
		);
	}

	$order = strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';

	if ( 'DESC' === $order ) {
		$ids = array_reverse( $ids );
	}

	$total = count( $ids );

	if ( $limit > 0 ) {
		$ids = array_slice( $ids, ( $page - 1 ) * $limit, $limit );
	}

	if ( ! empty( $args['paginate'] ) ) {
		return (object) array(
			'products'     => 'ids' === $return ? $ids : array_map( 'wc_get_product', $ids ),
			'total'        => $total,
			'max_num_pages' => $limit > 0 ? (int) ceil( $total / $limit ) : 1,
		);
	}

	if ( 'ids' === $return ) {
		return $ids;
	}

	return array_map( 'wc_get_product', $ids );
}

function wc_get_product_id_by_sku( string $sku ): int {
	return (int) ( WPS_Test_State::$skus[ $sku ] ?? 0 );
}

/**
 * @param array<string, mixed> $args
 * @return WC_Order|WP_Error
 */
function wc_create_order( array $args = array() ) {
	return new WC_Order( $args );
}

/**
 * @param array<string, mixed> $args
 * @return array<int, int|WC_Order>
 */
function wc_get_order( $order = 0 ) {
	$id = $order instanceof WC_Order ? $order->get_id() : (int) $order;

	return WPS_Test_State::$orders[ $id ] ?? false;
}

function wc_get_orders( array $args = array() ) {
	$limit = isset( $args['limit'] ) ? (int) $args['limit'] : -1;
	$ids   = array_keys( WPS_Test_State::$orders );
	sort( $ids );

	if ( isset( $args['meta_key'] ) ) {
		$key   = (string) $args['meta_key'];
		$value = (string) ( $args['meta_value'] ?? '' );

		$ids = array_values(
			array_filter(
				$ids,
				static fn( int $id ): bool => (string) WPS_Test_State::$orders[ $id ]->get_meta( $key ) === $value
			)
		);
	}

	if ( $limit > 0 ) {
		$ids = array_slice( $ids, 0, $limit );
	}

	if ( 'ids' === ( $args['return'] ?? 'objects' ) ) {
		return $ids;
	}

	return array_map( static fn( int $id ): WC_Order => WPS_Test_State::$orders[ $id ], $ids );
}

/**
 * @return array<string, string>
 */
function wc_get_order_statuses(): array {
	return array(
		'wc-pending'    => 'Függőben lévő fizetés',
		'wc-processing' => 'Feldolgozás alatt',
		'wc-on-hold'    => 'Függőben',
		'wc-completed'  => 'Teljesítve',
		'wc-cancelled'  => 'Törölve',
		'wc-refunded'   => 'Visszatérítve',
		'wc-failed'     => 'Sikertelen',
	);
}

function wc_tax_enabled(): bool {
	return WPS_Test_State::$tax_enabled;
}

function wc_prices_include_tax(): bool {
	return 'yes' === get_option( 'woocommerce_prices_include_tax', 'no' );
}

/**
 * @param array<string, mixed> $args
 */
function wc_get_price_including_tax( $product, array $args = array() ): float {
	$price = (float) ( $args['price'] ?? ( $product instanceof WC_Product ? $product->get_price() : 0 ) );

	if ( ! wc_tax_enabled() || wc_prices_include_tax() ) {
		return $price;
	}

	$class = $product instanceof WC_Product ? $product->get_tax_class() : '';
	$rates = WC_Tax::get_rates( $class );

	if ( array() === $rates ) {
		return $price;
	}

	$first = reset( $rates );
	$rate  = (float) ( $first['rate'] ?? 0 );

	return round( $price * ( 1 + $rate / 100 ), 4 );
}

function wc_attribute_label( string $name, $product = null ): string {
	if ( isset( WPS_Test_State::$attribute_labels[ $name ] ) ) {
		return WPS_Test_State::$attribute_labels[ $name ];
	}

	if ( str_starts_with( $name, 'pa_' ) ) {
		$name = substr( $name, 3 );
	}

	return ucwords( str_replace( array( '-', '_' ), ' ', $name ) );
}

function wc_price( float $price, array $args = array() ): string {
	return number_format( $price, 0, '.', ' ' ) . ' Ft';
}

function wc_get_price_decimals(): int {
	return 2;
}

function get_woocommerce_currency(): string {
	return WPS_Test_State::$currency;
}

function wc_get_currency_symbol( string $currency = '' ): string {
	return 'Ft';
}

function wc_get_page_id( string $page ): int {
	return 0;
}

function wc_get_cart_url(): string {
	return home_url( '/cart/' );
}

function wc_get_checkout_url(): string {
	return home_url( '/checkout/' );
}
