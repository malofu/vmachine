<?php

declare(strict_types=1);

use VendingMachine\Domain\Product;
use VendingMachine\Domain\UnknownProductException;

it('prices each product in cents', function (Product $product, int $priceInCents) {
    expect($product->price())->toBe($priceInCents);
})->with([
    'Water' => [Product::Water, 65],
    'Juice' => [Product::Juice, 100],
    'Soda' => [Product::Soda, 150],
]);

it('resolves a product from its selector, ignoring case', function (string $selector, Product $product) {
    expect(Product::fromSelector($selector))->toBe($product);
})->with([
    'WATER' => ['WATER', Product::Water],
    'juice' => ['juice', Product::Juice],
    'Soda' => ['Soda', Product::Soda],
]);

it('rejects an unknown selector', function () {
    expect(fn () => Product::fromSelector('COLA'))->toThrow(UnknownProductException::class);
});
