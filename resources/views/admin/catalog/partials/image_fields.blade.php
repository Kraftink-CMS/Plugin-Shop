<div class="border-top pt-3">
    <x-admin::media-picker name="image_id" :value="$selectedImage" context="shop-image" upload-name="image"
        :label="__('shop::admin.products.images.image')" :help="__('shop::admin.products.images.image_help')"
        :empty-label="__('shop::admin.products.images.no_image')" />
</div>
