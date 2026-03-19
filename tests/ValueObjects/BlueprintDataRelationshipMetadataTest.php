<?php

use Lartisan\Architect\ValueObjects\BlueprintData;


it('round-trips relationship metadata through blueprint form data', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'comments',
        'model_name' => 'Comment',
        'columns' => [
            [
                'name' => 'post_id',
                'type' => 'foreignId',
                'relationship_table' => 'posts',
                'relationship_title_column' => 'headline',
            ],
        ],
    ]);

    $formData = $blueprint->toFormData();

    expect($formData['columns'][0]['relationship_table'] ?? null)->toBe('posts')
        ->and($formData['columns'][0]['relationship_title_column'] ?? null)->toBe('headline');
});
