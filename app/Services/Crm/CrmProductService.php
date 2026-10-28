<?php

/**
 * Tourfecto - CRM Product Catalog & Deal Line Items Service (المرحلة 13 - G3)
 * @version 1.0.0
 *
 * كتالوج منتجات (CRUD) + ربط بنود بالصفقات مع إعادة حساب قيمة الصفقة
 * = Σ (سعر × كمية) − خصومات. الميزة يملكها كل المنافسين الكبار
 * (Pipedrive/Zoho/Freshsales بصراحة). Additive بالكامل: جداول جديدة
 * (crm_products / crm_deal_items) ولا تعديل على منطق CrmController
 * الأصلي - القيمة تُحدَّث عبر CrmProductService فقط بعد أي تغيير في
 * البنود.
 *
 * ملاحظة إعادة الحساب: نقاط نهاية CrmController::createDeal الأصلية
 * لسه بتكتب value يدويًا - هنا نُعيد كتابتها فقط عند تعديل البنود عبر
 * هذه الخدمة (أي أن التكامل تلقائي من ناحية البنود، لا يمس الإدخال
 * اليدوي الأصلي).
 */
class CrmProductService
{
    // ------------------------------------------------------------
    // المنتجات
    // ------------------------------------------------------------

    public function listProducts(int $userId, bool $onlyActive = false): array
    {
        return (new CrmProduct())->forUser($userId, $onlyActive);
    }

    public function createProduct(int $userId, array $data): CrmProduct
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new Exception('اسم المنتج مطلوب', 422);
        }
        $price = (float) ($data['price'] ?? 0);
        if ($price < 0) {
            throw new Exception('السعر لا يمكن أن يكون سالبًا', 422);
        }

        $product = new CrmProduct([
            'user_id' => $userId,
            'name' => $name,
            'description' => !empty($data['description']) ? trim((string) $data['description']) : null,
            'sku' => !empty($data['sku']) ? trim((string) $data['sku']) : null,
            'price' => $price,
            'currency' => (string) ($data['currency'] ?? 'USD'),
            'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        ]);
        $product->save();
        return $product;
    }

    public function updateProduct(int $userId, int $productId, array $data): CrmProduct
    {
        $product = (new CrmProduct())->findOwned($userId, $productId);
        if (!$product) {
            throw new Exception('المنتج غير موجود', 404);
        }
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new Exception('اسم المنتج مطلوب', 422);
            }
            $product->setAttribute('name', $name);
        }
        foreach (['description', 'sku'] as $field) {
            if (isset($data[$field])) {
                $value = trim((string) $data[$field]);
                $product->setAttribute($field, $value !== '' ? $value : null);
            }
        }
        if (isset($data['price'])) {
            $price = (float) $data['price'];
            if ($price < 0) {
                throw new Exception('السعر لا يمكن أن يكون سالبًا', 422);
            }
            $product->setAttribute('price', $price);
        }
        if (isset($data['currency'])) {
            $product->setAttribute('currency', (string) $data['currency']);
        }
        if (isset($data['is_active'])) {
            $product->setAttribute('is_active', (int) $data['is_active']);
        }

        $product->save();
        return $product;
    }

    public function deleteProduct(int $userId, int $productId): bool
    {
        $product = (new CrmProduct())->findOwned($userId, $productId);
        if (!$product) {
            throw new Exception('المنتج غير موجود', 404);
        }
        // بنود الصفقات المرتبطة تحتفظ باسم/سعر المنتج كلقطة (product_id
        // يتحول NULL عبر FK ON DELETE SET NULL) - لا يفقد أي سجل مبيعات.
        return $product->delete();
    }

    // ------------------------------------------------------------
    // بنود الصفقات
    // ------------------------------------------------------------

    /** التأكد أن الصفقة مملوكة للحساب (Tenant) */
    private function findOwnedDeal(int $userId, int $dealId): CrmDeal
    {
        $deal = (new CrmDeal())->find($dealId);
        if (!$deal || (int) $deal->getAttribute('owner_user_id') !== $userId) {
            throw new Exception('الصفقة غير موجودة', 404);
        }
        return $deal;
    }

    /** إعادة حساب قيمة الصفقة = Σ line_total (لا تلمس الإدخال اليدوي الأصلي) */
    private function recomputeDealValue(int $userId, int $dealId): void
    {
        $total = (new CrmDealItem())->totalForDeal($userId, $dealId);
        $deal = (new CrmDeal())->find($dealId);
        if ($deal) {
            $deal->setAttribute('value', $total);
            $deal->save();
        }
    }

    public function listDealItems(int $userId, int $dealId): array
    {
        $this->findOwnedDeal($userId, $dealId);
        return (new CrmDealItem())->forDeal($userId, $dealId);
    }

    public function addDealItem(int $userId, int $dealId, array $data): CrmDealItem
    {
        $this->findOwnedDeal($userId, $dealId);

        $productId = (int) ($data['product_id'] ?? 0);
        $productName = trim((string) ($data['product_name'] ?? ''));
        $unitPrice = (float) ($data['unit_price'] ?? 0);
        $quantity = (float) ($data['quantity'] ?? 1);
        $discount = (float) ($data['discount'] ?? 0);

        if ($productId > 0) {
            $product = (new CrmProduct())->findOwned($userId, $productId);
            if (!$product) {
                throw new Exception('المنتج غير موجود', 404);
            }
            if ($productName === '') {
                $productName = (string) $product->getAttribute('name');
            }
            if (!isset($data['unit_price'])) {
                $unitPrice = (float) $product->getAttribute('price');
            }
        }
        if ($productName === '') {
            throw new Exception('اسم المنتج/البند مطلوب', 422);
        }
        if ($unitPrice < 0 || $quantity <= 0 || $discount < 0) {
            throw new Exception('قيم غير صالحة (السعر/الكمية/الخصم)', 422);
        }

        $lineTotal = round(($unitPrice * $quantity) - $discount, 2);
        if ($lineTotal < 0) {
            $lineTotal = 0;
        }

        $item = new CrmDealItem([
            'user_id' => $userId,
            'deal_id' => $dealId,
            'product_id' => $productId > 0 ? $productId : null,
            'product_name' => $productName,
            'description' => ($data['description'] ?? null) !== '' ? trim((string) $data['description']) : null,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'discount' => $discount,
            'line_total' => $lineTotal,
        ]);
        $item->save();
        $this->recomputeDealValue($userId, $dealId);
        return $item;
    }

    public function updateDealItem(int $userId, int $dealId, int $itemId, array $data): CrmDealItem
    {
        $this->findOwnedDeal($userId, $dealId);
        $item = (new CrmDealItem())->findOwned($userId, $dealId, $itemId);
        if (!$item) {
            throw new Exception('البند غير موجود', 404);
        }

        if (isset($data['product_name'])) {
            $name = trim((string) $data['product_name']);
            if ($name === '') {
                throw new Exception('اسم البند مطلوب', 422);
            }
            $item->setAttribute('product_name', $name);
        }
        if (isset($data['description'])) {
            $item->setAttribute('description', trim((string) $data['description']));
        }
        if (isset($data['unit_price'])) {
            $item->setAttribute('unit_price', (float) $data['unit_price']);
        }
        if (isset($data['quantity'])) {
            $item->setAttribute('quantity', (float) $data['quantity']);
        }
        if (isset($data['discount'])) {
            $item->setAttribute('discount', (float) $data['discount']);
        }

        $unitPrice = (float) $item->getAttribute('unit_price');
        $quantity = (float) $item->getAttribute('quantity');
        $discount = (float) $item->getAttribute('discount');
        if ($unitPrice < 0 || $quantity <= 0 || $discount < 0) {
            throw new Exception('قيم غير صالحة (السعر/الكمية/الخصم)', 422);
        }
        $item->setAttribute('line_total', max(0, round(($unitPrice * $quantity) - $discount, 2)));
        $item->save();
        $this->recomputeDealValue($userId, $dealId);
        return $item;
    }

    public function removeDealItem(int $userId, int $dealId, int $itemId): bool
    {
        $this->findOwnedDeal($userId, $dealId);
        $item = (new CrmDealItem())->findOwned($userId, $dealId, $itemId);
        if (!$item) {
            throw new Exception('البند غير موجود', 404);
        }
        $result = $item->delete();
        $this->recomputeDealValue($userId, $dealId);
        return $result;
    }
}
