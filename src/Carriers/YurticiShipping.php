<?php

namespace Webkul\YurticiShipping\Carriers;

use Illuminate\Support\Facades\Http;
use Webkul\Shipping\Carriers\AbstractShipping;
use Webkul\Checkout\Models\CartShippingRate;
use Webkul\Checkout\Facades\Cart;

class YurticiShipping extends AbstractShipping
{
    /**
     * Shipping method code
     *
     * @var string
     */
    protected $code = 'yurticishipping';

    /**
     * Calculate shipping rates for the cart
     *
     * @return CartShippingRate|false
     */
    public function calculate(): false|CartShippingRate
    {
        if (!$this->isAvailable()) {
            return false;
        }

        /*$totalCost = collect(Cart::getCart()->items)
            ->sum(fn($item) => $this->calculateShippingCost($this->getChargeableWeight($item)) * $item->quantity);*/

        $totalWeight = collect(Cart::getCart()->items)
            ->sum(fn($item) => $this->getChargeableWeight($item) * $item->quantity);

        $totalCost = $this->calculateShippingCost($totalWeight);

        return $this->createShippingRateObject($totalCost);
    }

    /**
     * Calculate chargeable weight for an item (weight vs volumetric weight)
     *
     * @param object $item Cart item
     * @return float Chargeable weight in kg
     */
    private function getChargeableWeight(object $item): float
    {
        $product = $item->product;
        $dimensions = $this->getProductDimensions($product, $item);

        $volumetricWeight = ($dimensions['width'] * $dimensions['height'] * $dimensions['length']) / 3000;

        return max($dimensions['weight'], $volumetricWeight);
    }

    /**
     * Get product dimensions (height, width, length, weight)
     * Handles both simple and configurable products with API fallback
     *
     * @param object $product Product object
     * @param object $item Cart item object
     * @return array Dimensions array with defaults
     */
    private function getProductDimensions(object $product, object $item): array
    {
        $defaults = ['height' => 1, 'width' => 1, 'length' => 1, 'weight' => 1];

        if ($product->type !== 'configurable') {
            return array_merge($defaults, array_filter([
                'height' => $product->height,
                'width' => $product->width,
                'length' => $product->length,
                'weight' => $product->weight
            ]));
        }

        $productData = $this->fetchProductData($product->id);

        if (empty($productData)) {
            \Log::warning("404 Not Found Product ID: {$product->id}");
            return array_merge($defaults, array_filter([
                'height' => $product->height,
                'width' => $product->width,
                'length' => $product->length,
                'weight' => $product->weight
            ]));
        }

        $variant = collect($productData['variants'] ?? [])
            ->firstWhere('id', $item->child->product_id ?? null);

        return array_merge($defaults, array_filter([
            'height' => $variant['height'] ?? $product->height,
            'width' => $variant['width'] ?? $product->width,
            'length' => $variant['length'] ?? $product->length,
            'weight' => $variant['weight'] ?? $product->weight
        ]));
    }

    /**
     * Fetch product data from API including variants
     *
     * @param int $productId Product ID
     * @return array Product data from API or empty array on failure
     */
    private function fetchProductData(int $productId): array
    {
        try {
            $response = Http::withOptions(['verify' => false])
                ->get(env('APP_URL') . "/api/v1/products/{$productId}");

            return $response->successful() ? ($response->json()['data'] ?? []) : [];
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return [];
        }
    }

    /**
     * Create shipping rate object with calculated prices
     *
     * @param float $totalCost Total shipping cost in base currency
     * @return CartShippingRate Configured shipping rate object
     */
    private function createShippingRateObject(float $totalCost): CartShippingRate
    {
        return tap(new CartShippingRate, function($rate) use ($totalCost) {
            $rate->carrier = 'yurticishipping';
            $rate->carrier_title = $this->getConfigData('title');
            $rate->method = 'yurticishipping_standard';
            $rate->method_title = $this->getConfigData('title');
            $rate->method_description = $this->getConfigData('description');
            $rate->price = core()->convertPrice($totalCost, 'TRY');
            $rate->base_price = $totalCost;
        });
    }

    /**
     * Calculate shipping cost based on weight tiers
     *
     * @param float $weight Chargeable weight in kg
     * @return float Shipping cost in current currency
     */
    private function calculateShippingCost(float $weight): float
    {
        $exchangeRate = $this->getExchangeRate();

        $cost = match (true) {
            $weight == 0 => 101.5,
            $weight <= 5 => 135.51,
            $weight <= 10 => 155.71,
            $weight <= 15 => 185.95,
            $weight <= 20 => 255.78,
            $weight <= 25 => 326.52,
            $weight <= 30 => 397.57,
            default => $weight * 13.297,
        };

        return $cost / $exchangeRate;
    }

    /**
     * Get current currency exchange rate with fallback to 1
     *
     * @return float Exchange rate (always positive)
     */
    private function getExchangeRate(): float
    {
        $currentCurrency = core()->getCurrentCurrency()->id;
        $exchangeRateData = core()->getExchangeRate($currentCurrency);
        $rate = $exchangeRateData->rate ?? 1;

        return $rate > 0 ? $rate : 1;
    }
}
