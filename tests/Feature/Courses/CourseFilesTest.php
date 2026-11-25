<?php

namespace Tests\Feature\Courses;

use App\Services\Courses\CourseValidator;
use Tests\TestCase;

/**
 * The real course files in resources/courses must follow the format. With an
 * empty folder there is nothing to check and the test passes.
 */
class CourseFilesTest extends TestCase
{
    public function test_every_course_file_is_valid()
    {
        $files = glob(resource_path('courses').'/*.json') ?: [];
        $validator = new CourseValidator;

        foreach ($files as $file) {
            $name = basename($file);
            $data = json_decode((string) file_get_contents($file), true);

            $this->assertIsArray($data, "{$name} is not valid JSON.");
            $this->assertSame([], $validator->validate($data, strict: true), $name);
            $this->assertSame(basename($file, '.json'), $data['slug'], "{$name}: the slug must match the file name.");
        }

        $this->assertIsArray($files);
    }
}
