<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\SchoolFixture;
use Tests\TestCase;

class ResourceImageTest extends TestCase
{
    use RefreshDatabase, SchoolFixture;

    public function test_private_png_resource_is_validated_and_not_exposed_to_another_module_teacher(): void
    {
        [, , $teachers, $offerings, $students] = $this->schoolFixture();
        $this->fakeStorage();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5h0AAAAASUVORK5CYII=');
        $path = "/api/school/offerings/{$offerings[0]}/tools/materials";
        $id = $this->asToken($this->tokenFor($teachers[0]))->postJson($path, ['title' => 'Synthetic image', 'type' => 'file', 'file' => UploadedFile::fake()->createWithContent('image.png', $png)])->assertCreated()->assertJsonPath('category', 'image')->json('id');
        $this->asToken($this->tokenFor($teachers[1]))->getJson("/api/materials/$id/download")->assertForbidden();
        $this->asToken($this->tokenFor($students[0]))->get("/api/materials/$id/download")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->asToken($this->tokenFor($teachers[0]))->postJson($path, ['title' => 'Unsafe', 'type' => 'file', 'file' => UploadedFile::fake()->createWithContent('unsafe.png', '<svg><script>bad()</script></svg>')])->assertUnprocessable();
    }
}
