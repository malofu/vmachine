<?php

declare(strict_types=1);

use VendingMachine\Domain\Product;
use VendingMachine\Domain\ProductCatalogue;
use VendingMachine\Domain\UnknownProductException;

it('resolves a product from its selector, ignoring case', function () {
    $catalogue = ProductCatalogue::empty()->withProduct(Product::new('WATER', 65));

    expect($catalogue->get('water')->price())->toBe(65)
        ->and($catalogue->has('water'))->toBeTrue();
});

it('rejects an unknown selector', function () {
    expect(fn () => ProductCatalogue::empty()->get('COLA'))
        ->toThrow(UnknownProductException::class);
});

it('reprices a product by adding it again with the same selector', function () {
    $catalogue = ProductCatalogue::empty()
        ->withProduct(Product::new('WATER', 65))
        ->withProduct(Product::new('WATER', 70));

    expect($catalogue->get('WATER')->price())->toBe(70)
        ->and($catalogue->all())->toHaveCount(1);
});

it('lists every product it holds', function () {
    $catalogue = ProductCatalogue::empty()
        ->withProduct(Product::new('WATER', 65))
        ->withProduct(Product::new('SODA', 150));

    $selectors = array_map(fn (Product $p): string => $p->selector(), $catalogue->all());

    expect($selectors)->toBe(['WATER', 'SODA']);
});

it('removes a product', function () {
    $catalogue = ProductCatalogue::empty()
        ->withProduct(Product::new('WATER', 65))
        ->withoutProduct('water');

    expect($catalogue->has('WATER'))->toBeFalse();
});

it('refuses to remove a product it does not hold', function () {
    expect(fn () => ProductCatalogue::empty()->withoutProduct('COLA'))
        ->toThrow(UnknownProductException::class);
});
