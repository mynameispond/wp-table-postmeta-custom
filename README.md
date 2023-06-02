Installation
---------------

- Upload all files into plugins directory
- Activate plugin

How to Use
---------------

get_post_meta_custom(*int* $post_id, *string* $meta_key, *bool* $from_main = false)
- $post_id *Required* Post ID.
- $meta_key *Required* The meta key to retrieve.
- $from_main *Optional* Return default table if custom table return empty.

update_post_meta_custom(*int* $post_id, *string* $meta_key, *string* $meta_value)
- $post_id *Required* Post ID.
- $meta_key *Required* Metadata key.
- $meta_value *Required* Metadata value.

delete_post_meta_custom(*int* $post_id, *string* $meta_key)
- $post_id *Required* Post ID.
- $meta_key *Required* Metadata key.

Good luck!
