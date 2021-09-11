## A D7 Page Builder layout, from the `context` table, will look like this

```php
    [fieldblock-208a521aa519bc1ed37d8992aeffae83] => Array
        (
            [module] => fieldblock
            [delta] => 208a521aa519bc1ed37d8992aeffae83
            [region] => main_content_top_left
            [weight] => 0
        )

    [fieldblock-fda604d130a57f15015895c8268f20d2] => Array
        (
            [module] => fieldblock
            [delta] => fda604d130a57f15015895c8268f20d2
            [region] => main_content_top_left
            [weight] => 1
        )

    [fieldblock-c4c10ae36665adf0e722e7e3f4be74d4] => Array
        (
            [module] => fieldblock
            [delta] => c4c10ae36665adf0e722e7e3f4be74d4
            [region] => main_content_top_right
            [weight] => 0
        )

    [fieldblock-9c079efa827f76dea650869c5d2631e6] => Array
        (
            [module] => fieldblock
            [delta] => 9c079efa827f76dea650869c5d2631e6
            [region] => content_bottom
            [weight] => 0
        )
```

## Those fieldblock IDs need to be mapped to D8 fields as follows:

```
[fda604d130a57f15015895c8268f20d2] => field_flex_page_wysiwyg_a
[bf40687156268eaa30437ed84189f13e] => field_flex_page_wysiwyg_b
[9c079efa827f76dea650869c5d2631e6] => field_flex_page_fca_a
[2c880c8461bc3ce5a6ac19b2e7791346] => field_flex_page_fca_a
[208a521aa519bc1ed37d8992aeffae83] => field_flex_page_pu
[f4361d99a73eca8a4329c07d0724a554] => field_flex_page_hi
[6986914623a8e5646904aca42f9f452e] => field_flex_page_il_a
[738c0498378ce2c32ba571a0a69457dc] => field_flex_page_il_b
[669a6a1f32566fa73ea7974696027184] => field_flex_page_ql
[c4c10ae36665adf0e722e7e3f4be74d4] => field_flex_page_pl
[553096d7ea242fc7edcddc53f719d074] => field_flex_page_fh
[29dbb1cb2c1033fdddae49c21ad4a9f5] => field_flex_page_pca
[e01ea87c2dadf3edda4cc61011b33637] => field_flex_page_resource
[6f3b85225f51542463a88e53104f8753] => field_flex_page_wysiwyg_a
[9a6760fa853859ac84ff3a273ab79869] => field_flex_page_wysiwyg_b
[1a9dd8685785a44b58d5e24ed3f8996d] => field_flex_page_fca_a
[171f57c2269e221c96b732a464bae2e0] => field_flex_page_fca_a
[9bcf52bbed6b2a3ea84b55a58fdd9c55] => field_flex_page_pu
[8af3bd2d3cab537c77dbfbb55146ab7b] => field_flex_page_hi
[05826976d27bc7abbc4f0475ba10cb58] => field_flex_page_il_a
[21808b5e6c396dac8670f322f5c9e197] => field_flex_page_il_b
[eab8c417f7d28e9571473905cfebbd5b] => field_flex_page_ql
[1f11b5247df5b10da980b5681b637d17] => field_flex_page_pl
[205723da13bdadd816a716421b436a92] => field_flex_page_fh
[f28dec811f29578f018fae1a8458c9b4] => field_flex_page_pca
[75a75df6422c87166c75aa079ca98c3c] => field_flex_page_resource
```

The above map was generated using the following:
```php
// Loop over the fields defined in the variable.
$fields = [
  'field_wysiwyg_a' => 'field_flex_page_wysiwyg_a',
  'field_wysiwyg_b'=> 'field_flex_page_wysiwyg_b',
  'field_utexas_flex_content_area_a'=> 'field_flex_page_fca_a',
  'field_utexas_flex_content_area_b'=> 'field_flex_page_fca_a',
  'field_utexas_promo_units' => 'field_flex_page_pu',
  'field_utexas_hero_photo' => 'field_flex_page_hi',
  'field_utexas_image_link_a' => 'field_flex_page_il_a',
  'field_utexas_image_link_b' => 'field_flex_page_il_b',
  'field_utexas_quick_links' => 'field_flex_page_ql',
  'field_utexas_promo_list' => 'field_flex_page_pl',
  'field_utexas_featured_highlight' => 'field_flex_page_fh',
  'field_utexas_photo_content_area' => 'field_flex_page_pca',
  'field_utexas_resource' => 'field_flex_page_resource',
];
$map = [];
$bundles = ['moody_standard_page', 'moody_landing_page', 'moody_feature_page', 'moody_feature_page', 'moody_media_page'];
foreach ($bundles as $bundle) {
  $variable_name = 'fieldblock-node-' . $bundle . '-default';
  foreach ($fields as $d7 => $d8) {
    // Build the fieldblock info.
    $fieldblock_id = md5($variable_name .'-'. $d7);
    $map[$fieldblock_id] = $d8;
  }
}
```

## D7-prepared data will look like this

```php
    $sections = [];
    $sections[] = [
      'layout' => 'layout_utexas_50_50',
      'components' => [
        'field_block:node:utexas_flex_page:field_flex_page_pu' => ['type' => 'field_block', 'region'=>  'left', 'weight' => 0],
        'field_block:node:utexas_flex_page:field_flex_page_pl' => ['type' => 'field_block', 'region'=>  'right', 'weight' => 0],
        'field_block:node:utexas_flex_page:field_flex_page_wysiwyg_b' => ['type' => 'field_block',  'region' => 'left', 'weight' => 1],
      ],
    ];
    $sections[] = [
      'layout' => 'layout_utexas_fullwidth',
      'components' => [
        'field_block:node:utexas_flex_page:field_flex_page_fh' => ['type' => 'field_block', 'region'=>  'main', 'weight' => 0],
      ],
    ];
```

## D8 Layout Builder data will look like this:

```php
    $data = [
      ['section' => new Section(
          'layout_utexas_50_50',
          [],
          ['section' => new SectionComponent('ec93b42c-0668-4b92-ae60-d9091684440f', 'left', [
            'id' => 'field_block:node:utexas_flex_page:field_flex_page_pu',
            'context_mapping' => [
              'entity' => 'layout_builder.entity',
            ],
          ])]
        ),
      ],
    ];
  ```

### It will be processed like this:
```php
foreach ($sections as $section_delta => $section) {
          $sections[$section_delta] = new Section(
            $section['layout_id'],
            $section['layout_settings'],
            array_map(function (array $component) {
              return (new SectionComponent(
                $component['uuid'],
                $component['region'],
                $component['configuration'],
                $component['additional']
              ))->setWeight($component['weight']);
            }, $section['components'])
          );
        }
```

### It will be saved into `node__layout_builder__layout` like this:
```php
__PHP_Incomplete_Class Object
(
    [__PHP_Incomplete_Class_Name] => Drupal\layout_builder\Section
    [layoutId:protected] => layout_utexas_50_50
    [layoutSettings:protected] => Array
        (
        )

    [components:protected] => Array
        (
            [ec93b42c-0668-4b92-ae60-d9091684440f] => __PHP_Incomplete_Class Object
                (
                    [__PHP_Incomplete_Class_Name] => Drupal\layout_builder\SectionComponent
                    [uuid:protected] => ec93b42c-0668-4b92-ae60-d9091684440f
                    [region:protected] => left
                    [configuration:protected] => Array
                        (
                            [id] => field_block:node:utexas_flex_page:field_flex_page_pu
                            [label] => Promo Units
                            [provider] => layout_builder
                            [label_display] => 0
                            [formatter] => Array
                                (
                                    [label] => hidden
                                    [type] => entity_reference_revisions_entity_view
                                    [settings] => Array
                                        (
                                            [view_mode] => default
                                        )

                                    [third_party_settings] => Array
                                        (
                                        )

                                )

                            [context_mapping] => Array
                                (
                                    [entity] => layout_builder.entity
                                )

                        )

                    [weight:protected] => 0
                    [additional:protected] => Array
                        (
                        )

                )

            [93535cd8-5043-4b58-a823-ba60b483a794] => __PHP_Incomplete_Class Object
                (
                    [__PHP_Incomplete_Class_Name] => Drupal\layout_builder\SectionComponent
                    [uuid:protected] => 93535cd8-5043-4b58-a823-ba60b483a794
                    [region:protected] => right
                    [configuration:protected] => Array
                        (
                            [id] => field_block:node:utexas_flex_page:field_flex_page_pl
                            [label] => Promo List
                            [provider] => layout_builder
                            [label_display] => 0
                            [formatter] => Array
                                (
                                    [label] => hidden
                                    [type] => entity_reference_revisions_entity_view
                                    [settings] => Array
                                        (
                                            [view_mode] => default
                                        )

                                    [third_party_settings] => Array
                                        (
                                        )

                                )

                            [context_mapping] => Array
                                (
                                    [entity] => layout_builder.entity
                                )

                        )

                    [weight:protected] => 0
                    [additional:protected] => Array
                        (
                        )

                )

        )

)
```
