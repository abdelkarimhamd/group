<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Services\ProductPhotos;
use App\Modules\Identity\Enums\AdminRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

/**
 * Product photos are stored as WebP no larger than groupbuy.media.photo_max_px,
 * so a customer on a weak network does not download the supplier's original.
 */
class ProductPhotosTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    #[Test]
    public function a_large_photo_is_stored_as_a_smaller_webp(): void
    {
        Storage::fake('public');
        [, $token] = $this->adminWithToken(AdminRole::Sourcing);
        $product = Product::factory()->active()->create(['category_id' => Category::factory()->create()->id]);
        $upload = self::photo('jpg', 3000, 2000);

        $this->withToken($token)->post("/api/v1/admin/products/{$product->uuid}/images", ['file' => $upload], ['Accept' => 'application/json'])
            ->assertCreated();

        $path = ProductImage::sole()->path;
        $this->assertStringEndsWith('.webp', $path);
        $stored = Storage::disk('public')->get($path);
        [$width, $height, $type] = getimagesizefromstring($stored);
        $this->assertSame([1600, 1067, IMAGETYPE_WEBP], [$width, $height, $type]);
        $this->assertLessThan($upload->getSize(), strlen($stored));
        // Only the stored photo is left: the temporary WebP file is gone.
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function a_transparent_png_keeps_its_transparency(): void
    {
        $image = imagecreatetruecolor(2400, 1200);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 600, 300, 1800, 900, imagecolorallocate($image, 200, 30, 30));
        $file = tempnam(sys_get_temp_dir(), 'png');
        imagepng($image, $file);
        $upload = new UploadedFile($file, 'logo.png', 'image/png', null, true);

        $photo = ProductPhotos::optimize($upload);

        $this->assertNotSame($upload, $photo);
        $this->assertSame('logo.webp', $photo->getClientOriginalName());
        $webp = imagecreatefromwebp($photo->getPathname());
        $this->assertSame([1600, 800], [imagesx($webp), imagesy($webp)]);
        $this->assertSame(127, (imagecolorat($webp, 10, 10) >> 24) & 0x7F, 'The corner stays transparent.');
        $this->assertSame(0, (imagecolorat($webp, 800, 400) >> 24) & 0x7F, 'The middle stays opaque.');

        ProductPhotos::discard($photo, $upload);
        $this->assertFileDoesNotExist($photo->getPathname());
        @unlink($file);
    }

    #[Test]
    public function a_phone_photo_is_turned_the_way_its_exif_says(): void
    {
        // 400 x 200, a red block in the top left corner, EXIF orientation 6:
        // shown turned a quarter clockwise, so the block ends up top right.
        $image = imagecreatetruecolor(400, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, 99, 49, imagecolorallocate($image, 255, 0, 0));
        for ($i = 0; $i < 300; $i++) {
            imagesetpixel($image, random_int(150, 399), random_int(60, 199), imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        ob_start();
        imagejpeg($image, null, 100);
        $jpeg = (string) ob_get_clean();
        $tiff = "II\x2A\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00".$tiff;
        $file = tempnam(sys_get_temp_dir(), 'exif');
        file_put_contents($file, substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2));
        $this->assertSame(6, exif_read_data($file)['Orientation']);
        $upload = new UploadedFile($file, 'phone.jpg', 'image/jpeg', null, true);

        $photo = ProductPhotos::optimize($upload);

        $webp = imagecreatefromwebp($photo->getPathname());
        $this->assertSame([200, 400], [imagesx($webp), imagesy($webp)]);
        $corner = imagecolorsforindex($webp, imagecolorat($webp, 190, 10));
        $this->assertGreaterThan(200, $corner['red']);
        $this->assertLessThan(60, $corner['green']);

        ProductPhotos::discard($photo, $upload);
        @unlink($file);
    }

    #[Test]
    public function a_file_gd_cannot_read_is_kept_as_uploaded(): void
    {
        $upload = UploadedFile::fake()->create('photo.jpg', 10, 'image/jpeg');

        $this->assertSame($upload, ProductPhotos::optimize($upload));
    }

    #[Test]
    public function a_photo_too_large_to_decode_is_kept_as_uploaded(): void
    {
        config(['groupbuy.media.photo_max_source_pixels' => 1000]);
        $upload = self::photo('jpg', 100, 100);

        $this->assertSame($upload, ProductPhotos::optimize($upload));
    }

    private static function photo(string $extension, int $width, int $height): UploadedFile
    {
        // Noise, like a real photo, so the original is not trivially small.
        $image = imagecreatetruecolor($width, $height);
        for ($i = 0; $i < 4000; $i++) {
            imagefilledellipse($image, random_int(0, $width), random_int(0, $height), random_int(20, 200), random_int(20, 200), imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        $file = tempnam(sys_get_temp_dir(), 'photo');
        imagejpeg($image, $file, 95);

        return new UploadedFile($file, "photo.{$extension}", 'image/jpeg', null, true);
    }
}
