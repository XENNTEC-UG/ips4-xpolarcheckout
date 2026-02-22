<?php
/**
 * Automation test: discount coupon safety + stale endpoint-id fallback (A13)
 *
 * Run:
 *   docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_discount_coupon_safety.php
 *
 * Validates:
 *   1.  buildDiscountSafetyFallbackLineItems() helper exists
 *   2.  auth() wraps createOneTimeStripeCoupon() in try/catch
 *   3.  coupon catch block logs xpolarcheckout_coupon and uses fallback line-items
 *   4.  createOneTimeStripeCoupon() sanitizes and caps coupon name to 40 chars
 *   5.  fetchWebhookEndpoint() only returns ID lookup when response contains id
 *   6.  fetchWebhookEndpoint() keeps legacy URL fallback path
 */

$pass = 0;
$fail = 0;
$total = 0;

function assert_true( $condition, $label )
{
	global $pass, $fail, $total;
	$total++;
	if ( $condition )
	{
		$pass++;
		echo "  [PASS] {$label}\n";
	}
	else
	{
		$fail++;
		echo "  [FAIL] {$label}\n";
	}
}

$appBase = '/var/www/html/applications/xpolarcheckout';
$gatewayPath = $appBase . '/sources/XPolarCheckout/XPolarCheckout.php';
$gatewaySource = file_get_contents( $gatewayPath );

echo "=== Discount Coupon Safety & Endpoint Fallback Tests (A13) ===\n\n";

echo "1. Fallback helper method\n";
assert_true(
	mb_strpos( $gatewaySource, 'function buildDiscountSafetyFallbackLineItems' ) !== FALSE,
	'buildDiscountSafetyFallbackLineItems() exists'
);
echo "\n";

echo "2. auth() coupon creation guarded by try/catch\n";
$authMatch = preg_match( '/function\s+auth\s*\(.*?\n\t\}/s', $gatewaySource, $authBodyMatch );
$authBody = isset( $authBodyMatch[0] ) ? $authBodyMatch[0] : '';
assert_true( $authMatch === 1, 'auth() method found' );
assert_true(
	mb_strpos( $authBody, 'createOneTimeStripeCoupon' ) !== FALSE,
	'auth() references createOneTimeStripeCoupon()'
);
assert_true(
	preg_match( '/try\s*\{[\s\S]*createOneTimeStripeCoupon[\s\S]*\}\s*catch\s*\(\s*\\\\Exception/s', $authBody ) === 1,
	'auth() wraps coupon creation in try/catch'
);
assert_true(
	mb_strpos( $authBody, "'xpolarcheckout_coupon'" ) !== FALSE,
	'coupon catch block logs xpolarcheckout_coupon'
);
assert_true(
	mb_strpos( $authBody, 'buildDiscountSafetyFallbackLineItems' ) !== FALSE,
	'coupon catch block uses fallback line-item helper'
);
echo "\n";

echo "3. Coupon name sanitation and 40-char cap\n";
$couponMatch = preg_match( '/function\s+createOneTimeStripeCoupon\s*\(.*?\n\t\}/s', $gatewaySource, $couponBodyMatch );
$couponBody = isset( $couponBodyMatch[0] ) ? $couponBodyMatch[0] : '';
assert_true( $couponMatch === 1, 'createOneTimeStripeCoupon() method found' );
assert_true(
	mb_strpos( $couponBody, '\\strip_tags' ) !== FALSE,
	'coupon name strips HTML tags'
);
assert_true(
	mb_strpos( $couponBody, "preg_replace( '/\\s+/', ' ', \$couponName )" ) !== FALSE,
	'coupon name normalizes whitespace'
);
assert_true(
	mb_strpos( $couponBody, 'mb_strlen( $couponName ) > 40' ) !== FALSE,
	'coupon name enforces 40-char max check'
);
assert_true(
	mb_strpos( $couponBody, 'mb_substr( $couponName, 0, 40 )' ) !== FALSE,
	'coupon name truncates to first 40 chars'
);
echo "\n";

echo "4. fetchWebhookEndpoint() stale ID fallback behavior\n";
$fetchMatch = preg_match( '/function\s+fetchWebhookEndpoint\s*\(.*?\n\t\}/s', $gatewaySource, $fetchBodyMatch );
$fetchBody = isset( $fetchBodyMatch[0] ) ? $fetchBodyMatch[0] : '';
assert_true( $fetchMatch === 1, 'fetchWebhookEndpoint() method found' );
assert_true(
	mb_strpos( $fetchBody, '$endpointById' ) !== FALSE,
	'ID lookup result is stored in local variable'
);
assert_true(
	mb_strpos( $fetchBody, "isset( \$endpointById['id'] )" ) !== FALSE,
	'ID lookup returns only when endpoint id is present'
);
assert_true(
	mb_strpos( $fetchBody, 'Legacy fallback: find by URL match' ) !== FALSE,
	'legacy URL-match fallback path remains'
);
assert_true(
	mb_strpos( $fetchBody, "https://api.stripe.com/v1/webhook_endpoints" ) !== FALSE,
	'fallback still queries webhook_endpoints list'
);
echo "\n";

echo "5. Mock coupon-name normalization behavior\n";
$mockName = "   <b>Long Coupon Label</b> " . str_repeat( 'X', 80 ) . "   ";
$mockName = strip_tags( (string) $mockName );
$mockName = trim( (string) preg_replace( '/\s+/', ' ', $mockName ) );
if ( mb_strlen( $mockName ) > 40 )
{
	$mockName = rtrim( mb_substr( $mockName, 0, 40 ) );
}
assert_true(
	mb_strlen( $mockName ) <= 40,
	'mock normalized name is <= 40 chars'
);
assert_true(
	mb_strpos( $mockName, '<b>' ) === FALSE && mb_strpos( $mockName, '</b>' ) === FALSE,
	'mock normalized name contains no HTML tags'
);
echo "\n";

echo "=== Results: {$pass}/{$total} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );

