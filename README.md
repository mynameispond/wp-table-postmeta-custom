# WP Table Postmeta Custom

เวอร์ชัน: `0.2.1`

ปลั๊กอิน WordPress สำหรับสร้างและใช้งานตาราง `postmeta` แยกหลายตารางตาม `slug` เพื่อช่วยแยกกลุ่มข้อมูล ลดภาระจาก `wp_postmeta` หลัก และเพิ่มเครื่องมือหลังบ้านสำหรับจัดการข้อมูล, schema, index, import/export และ sync ข้อมูลกับ `wp_postmeta`

ปลั๊กอินนี้ยังมี helper function สำหรับอ่าน/เขียน meta จากตาราง custom และรองรับการ query ผ่าน `WP_Query` ด้วย argument ชื่อ `meta_query_custom`

## ความสามารถหลัก

- สร้างตาราง custom postmeta ได้หลายตารางในรูปแบบ `{$wpdb->prefix}postmeta_<slug>`
- มีตาราง default ชื่อ `postmeta_wppc`
- สร้าง/ลบตารางจากหลังบ้านได้ ยกเว้นตาราง default
- มีเมนูหลังบ้านใต้ `Tools > WP Postmeta Custom`
- จัดการข้อมูลในตารางได้: เพิ่ม, แก้ไข, ลบ, ค้นหา, แบ่งหน้า
- มี helper API กลาง เช่น `wppc_get_post_meta()`, `wppc_update_post_meta()`, `wppc_delete_post_meta()`
- สร้างฟังก์ชันตาม slug อัตโนมัติ เช่น `get_post_meta_product()`
- รองรับ `WP_Query` ผ่าน `meta_query_custom`
- ใช้ร่วมกับ `meta_query` ปกติของ WordPress ได้
- กำหนด schema ต่อ `meta_key` ได้ เช่น `number`, `boolean`, `json`, `date`, `datetime`
- เพิ่ม/ลบ index จาก preset ได้
- Import/Export ข้อมูลเป็น JSON หรือ CSV
- Sync ข้อมูลกับ `wp_postmeta` ได้แบบ batch/cursor
- มี nonce และ capability check สำหรับ action หลังบ้าน

## ความต้องการระบบ

- WordPress ที่มี `$wpdb` และระบบ admin ปกติ
- PHP ที่รองรับ syntax แบบ WordPress plugin มาตรฐาน
- MySQL/MariaDB ที่รองรับ table/index ตามโครงสร้างของ WordPress
- ผู้ใช้งานหลังบ้านต้องมี capability `manage_options`

## การติดตั้ง

1. วางไฟล์ปลั๊กอินไว้ในโฟลเดอร์ `wp-content/plugins/wp-table-postmeta-custom/`
2. เข้าเมนู `Plugins` ใน WordPress admin
3. กด `Activate`
4. หลัง activate ระบบจะสร้างตาราง default:

```text
{$wpdb->prefix}postmeta_wppc
```

ตัวอย่าง ถ้า prefix เป็น `wp_` จะได้:

```text
wp_postmeta_wppc
```

## โครงสร้างตาราง

ทุกตาราง custom ใช้โครงสร้างใกล้เคียง `wp_postmeta`:

```sql
meta_id    bigint unsigned auto increment
post_id    bigint unsigned
meta_key   varchar(255)
meta_value longtext
```

index เริ่มต้น:

- `PRIMARY KEY (meta_id)`
- `KEY post_id (post_id)`
- `KEY meta_key (meta_key(191))`

## กติกา slug

slug ใช้สำหรับสร้างชื่อ table และชื่อ dynamic function

กติกาที่อนุญาต:

- ต้องขึ้นต้นด้วยตัวอักษร `a-z`
- ใช้ได้เฉพาะ `a-z`, `0-9`, `_`
- ระบบจะแปลงเป็น lowercase
- ชื่อตารางจริงต้องไม่ยาวเกินข้อจำกัดของ MySQL
- ห้ามใช้ slug ที่ทำให้ชื่อ dynamic function ชนกับฟังก์ชันที่มีอยู่แล้ว

ตัวอย่าง slug ที่ถูกต้อง:

- `product`
- `product_meta`
- `campaign2026`
- `seo_data`

ตัวอย่าง slug ที่ไม่ถูกต้อง:

- `1product`
- `product-meta`
- `product meta`
- `สินค้า`

หมายเหตุ: slug `custom` จะชนกับฟังก์ชัน backward compatibility เช่น `get_post_meta_custom()` จึงไม่ควรใช้

## เมนูหลังบ้าน

เมนูหลักอยู่ที่:

```text
Tools > WP Postmeta Custom
```

หน้าในระบบ:

- `ภาพรวม`: แสดงจำนวนตาราง, จำนวนแถวรวม, slug default และ version
- `รายการประเภทตาราง`: สร้างและลบตารางตาม slug
- `จัดการข้อมูลตาราง`: เพิ่ม/แก้ไข/ลบข้อมูล, schema, index, import/export และ sync

## การสร้างตารางใหม่

ไปที่:

```text
Tools > WP Postmeta Custom > รายการประเภทตาราง
```

กรอก slug เช่น:

```text
product
```

ระบบจะสร้างตาราง:

```text
{$wpdb->prefix}postmeta_product
```

และ register slug ไว้ใน option:

```text
wppc_table_registry
```

ถ้าสร้างตารางไม่สำเร็จ ระบบจะไม่ register slug นั้น

## การลบตาราง

ลบได้ทุก slug ยกเว้น:

```text
wppc
```

เมื่อลบสำเร็จ ระบบจะ:

- `DROP TABLE` ตารางนั้น
- ลบ slug ออกจาก registry
- ลบ schema ของ slug นั้น
- ลบ sync state ของ slug นั้น

ถ้า `DROP TABLE` ไม่สำเร็จ ระบบจะไม่ลบ registry/schema/sync state

## Helper API

### อ่านค่า

```php
wppc_get_post_meta($table_slug, $post_id, $meta_key, $from_main = false);
```

ตัวอย่าง:

```php
$color = wppc_get_post_meta('product', 123, 'color');
```

ถ้า `$from_main = true` และไม่พบค่าในตาราง custom ระบบจะ fallback ไปอ่านจาก `wp_postmeta`

```php
$color = wppc_get_post_meta('product', 123, 'color', true);
```

### เพิ่ม/อัปเดตค่า

```php
wppc_update_post_meta($table_slug, $post_id, $meta_key, $meta_value);
```

ตัวอย่าง:

```php
wppc_update_post_meta('product', 123, 'color', 'blue');
```

ถ้าตารางยังไม่มีอยู่ ระบบจะพยายามสร้างตารางก่อนบันทึก

### ลบค่า

```php
wppc_delete_post_meta($table_slug, $post_id, $meta_key, $meta_value = null);
```

ตัวอย่างลบทุกแถวของ key นั้น:

```php
wppc_delete_post_meta('product', 123, 'color');
```

ตัวอย่างลบเฉพาะค่าที่ตรงกัน:

```php
wppc_delete_post_meta('product', 123, 'color', 'blue');
```

## Backward Compatibility API

ฟังก์ชันชุดนี้ชี้ไปที่ slug default `wppc`

```php
get_post_meta_custom($post_id, $meta_key, $from_main = false);
update_post_meta_custom($post_id, $meta_key, $meta_value);
delete_post_meta_custom($post_id, $meta_key, $meta_value = null);
```

ตัวอย่าง:

```php
update_post_meta_custom(123, 'score', 95);
$score = get_post_meta_custom(123, 'score');
```

## Dynamic Functions

เมื่อ register slug แล้ว ปลั๊กอินจะสร้างฟังก์ชันตาม slug อัตโนมัติใน hook `init`

ถ้า slug คือ:

```text
product
```

จะได้ฟังก์ชัน:

```php
get_post_meta_product($post_id, $meta_key, $from_main = false);
update_post_meta_product($post_id, $meta_key, $meta_value);
delete_post_meta_product($post_id, $meta_key, $meta_value = null);
```

ตัวอย่าง:

```php
update_post_meta_product(123, 'color', 'blue');
$color = get_post_meta_product(123, 'color');
```

ถ้าชื่อฟังก์ชันมีอยู่แล้ว ระบบจะไม่ประกาศซ้ำ

## พฤติกรรมเมื่อมีแถวซ้ำ

ตาราง custom ไม่บังคับ unique key ระหว่าง `post_id` และ `meta_key`

ถ้ามีหลายแถวที่ `post_id` และ `meta_key` เหมือนกัน:

- การอ่านค่าจะใช้แถวล่าสุดตาม `meta_id DESC`
- การ update ผ่าน helper จะอัปเดตแถวล่าสุดตาม `meta_id DESC`
- การลบแบบไม่ระบุ `$meta_value` จะลบทุกแถวที่ตรงกับ `post_id` และ `meta_key`

## Schema

Schema ใช้กำหนดชนิดข้อมูลต่อ `meta_key` ในแต่ละ slug

ไปที่:

```text
จัดการข้อมูลตาราง > กำหนดโครงสร้างข้อมูล (Schema)
```

ชนิดข้อมูลที่รองรับ:

- `text`
- `number`
- `boolean`
- `json`
- `date`
- `datetime`

### text

รับค่าได้ทั่วไป

```php
wppc_update_post_meta('product', 123, 'title', 'Sample Product');
```

### number

ค่าต้องผ่าน `is_numeric()`

```php
wppc_update_post_meta('product', 123, 'price', '199.50');
```

### boolean

ค่าที่รับได้:

- `0`
- `1`
- `'0'`
- `'1'`
- `true`
- `false`

### json

รับได้ทั้ง JSON string และ array/object จาก PHP

ตัวอย่าง JSON string:

```php
wppc_update_post_meta('product', 123, 'config', '{"enabled":true,"limit":10}');
```

ตัวอย่าง array:

```php
wppc_update_post_meta('product', 123, 'config', array(
    'enabled' => true,
    'limit' => 10,
));
```

ถ้า schema เป็น `json` ระบบจะ normalize ค่าเก็บเป็น JSON string และอ่านกลับเป็น array เมื่อ decode ได้

### date

รูปแบบ:

```text
YYYY-MM-DD
```

ตัวอย่าง:

```php
wppc_update_post_meta('product', 123, 'start_date', '2026-05-15');
```

### datetime

รูปแบบ:

```text
YYYY-MM-DD HH:MM:SS
```

ตัวอย่าง:

```php
wppc_update_post_meta('product', 123, 'published_at', '2026-05-15 14:30:00');
```

### Required

ถ้าติ๊ก required ระบบจะไม่ยอมรับค่าว่าง

ถ้าไม่ required ระบบจะยอมรับค่าว่างโดยไม่บังคับ type validation

## Index Manager

ไปที่:

```text
จัดการข้อมูลตาราง > จัดการดัชนี (Index)
```

Preset ที่มี:

- `idx_post_id_meta_key`: `(post_id, meta_key(191))`
- `idx_meta_key_post_id`: `(meta_key(191), post_id)`
- `idx_post_id_meta_key_value`: `(post_id, meta_key(191), meta_value(191))`

คำแนะนำ:

- ถ้าค้นหาตาม `post_id + meta_key` บ่อย ให้ใช้ `idx_post_id_meta_key`
- ถ้าค้นหาตาม `meta_key` ก่อน แล้วค่อยกรอง `post_id` ให้ใช้ `idx_meta_key_post_id`
- ถ้ากรองค่า `meta_value` สั้น ๆ ด้วย ให้พิจารณา `idx_post_id_meta_key_value`

## WP_Query: meta_query_custom

ปลั๊กอินเพิ่มการรองรับ argument:

```php
meta_query_custom
```

และเลือกตารางด้วย:

```php
meta_query_custom_table
```

### ตัวอย่างพื้นฐาน

```php
$query = new WP_Query(array(
    'post_type' => 'post',
    'meta_query_custom_table' => 'product',
    'meta_query_custom' => array(
        array(
            'key' => 'color',
            'value' => 'blue',
            'compare' => '=',
        ),
    ),
));
```

### ใช้ relation

```php
$query = new WP_Query(array(
    'post_type' => 'post',
    'meta_query_custom_table' => 'product',
    'meta_query_custom' => array(
        'relation' => 'OR',
        array(
            'key' => 'color',
            'value' => 'blue',
            'compare' => '=',
        ),
        array(
            'key' => 'size',
            'value' => 'large',
            'compare' => '=',
        ),
    ),
));
```

### ใช้ร่วมกับ meta_query ปกติ

```php
$query = new WP_Query(array(
    'post_type' => 'post',
    'meta_query' => array(
        array(
            'key' => '_thumbnail_id',
            'compare' => 'EXISTS',
        ),
    ),
    'meta_query_custom_table' => 'product',
    'meta_query_custom' => array(
        array(
            'key' => 'color',
            'value' => 'blue',
            'compare' => '=',
        ),
    ),
));
```

### compare ที่รองรับ

- `=`
- `!=`
- `>`
- `>=`
- `<`
- `<=`
- `LIKE`
- `NOT LIKE`
- `IN`
- `NOT IN`
- `BETWEEN`
- `NOT BETWEEN`
- `EXISTS`
- `NOT EXISTS`

### type/cast ที่รองรับ

- `CHAR`
- `BINARY`
- `SIGNED`
- `UNSIGNED`
- `DECIMAL(20,6)`
- `DATE`
- `DATETIME`
- `TIME`
- `NUMERIC` จะถูก map เป็น `SIGNED`

ตัวอย่าง query ตัวเลข:

```php
$query = new WP_Query(array(
    'post_type' => 'post',
    'meta_query_custom_table' => 'product',
    'meta_query_custom' => array(
        array(
            'key' => 'price',
            'value' => '100',
            'compare' => '>=',
            'type' => 'NUMERIC',
        ),
    ),
));
```

## Import / Export

ไปที่:

```text
จัดการข้อมูลตาราง > นำเข้า/ส่งออกข้อมูล
```

รองรับ:

- JSON
- CSV

ขนาดไฟล์นำเข้าสูงสุด:

```text
10MB
```

คอลัมน์/field ขั้นต่ำที่ต้องมี:

- `post_id`
- `meta_key`
- `meta_value`

### Export JSON

JSON export จะส่งค่าแบบ logical value เท่าที่อ่านได้จาก storage และเพิ่ม field:

```text
meta_value_storage: "logical"
```

ตัวอย่าง:

```json
[
  {
    "meta_id": "1",
    "post_id": "123",
    "meta_key": "config",
    "meta_value": {
      "enabled": true,
      "limit": 10
    },
    "meta_value_storage": "logical"
  }
]
```

### Export CSV

CSV export จะส่งค่า raw ใน `meta_value` และเพิ่มคอลัมน์:

```text
meta_value_storage
```

ค่าที่เป็นไปได้:

- `plain`
- `serialized`

ตัวอย่าง header:

```csv
meta_id,post_id,meta_key,meta_value,meta_value_storage
```

### Import JSON

ตัวอย่าง:

```json
[
  {
    "post_id": 123,
    "meta_key": "color",
    "meta_value": "blue"
  },
  {
    "post_id": 123,
    "meta_key": "config",
    "meta_value": {
      "enabled": true,
      "limit": 10
    }
  }
]
```

### Import CSV

ตัวอย่าง:

```csv
post_id,meta_key,meta_value
123,color,blue
123,size,large
```

ถ้ามี `meta_value_storage=serialized` ระบบจะพยายามรักษาค่า serialized เดิมไว้ เพื่อป้องกัน double serialize เมื่อ import จากไฟล์ export เดิม

## Sync กับ wp_postmeta

ไปที่:

```text
จัดการข้อมูลตาราง > ซิงก์ข้อมูลกับ wp_postmeta
```

ทิศทางที่รองรับ:

- `from_main`: จาก `wp_postmeta` ไปตาราง custom
- `to_main`: จากตาราง custom ไป `wp_postmeta`

การทำงาน:

- ทำงานเป็น batch
- ใช้ `cursor` จาก `meta_id`
- ตั้ง batch size ได้ระหว่าง `10` ถึง `1000`
- เก็บสถานะ `running`, `direction`, `cursor`, `copied`, `skipped`, `last_message`
- กด run batch ได้ทีละรอบ
- reset sync state ได้

ข้อควรระวัง:

- Sync เป็นการกดรันจากหน้า admin ยังไม่มี cron อัตโนมัติ
- ควรทดสอบบน staging ก่อน sync ข้อมูลจำนวนมาก
- ถ้ามี schema แล้วข้อมูลไม่ผ่าน validation แถวนั้นจะถูกข้าม

## Security และ Validation

ปลั๊กอินมีการป้องกันหลัก ๆ ดังนี้:

- ทุกหน้า admin ตรวจ `current_user_can('manage_options')`
- ทุก action admin ใช้ nonce ผ่าน `check_admin_referer()`
- slug ถูก validate ด้วย regex
- table name และ index name ถูก escape เป็น SQL identifier
- input จาก admin ใช้ `wp_unslash()` และ sanitize ตามชนิดข้อมูล
- output หลังบ้านใช้ `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()`
- export ต้องผ่าน nonce URL

## Option ที่ใช้

ปลั๊กอินเก็บข้อมูลสถานะใน WordPress options:

```text
wppc_table_registry
wppc_schema_registry
wppc_sync_state
```

## CSS หลังบ้าน

ไฟล์ CSS:

```text
assets/wppc-admin.css
```

จะถูก enqueue เฉพาะหน้าของปลั๊กอิน:

- `wppc-overview`
- `wppc-table-types`
- `wppc-data-manager`

## ข้อจำกัดที่ควรรู้

- ตาราง custom ไม่ sync อัตโนมัติกับ `wp_postmeta`
- ไม่มี unique constraint ระหว่าง `post_id` และ `meta_key`
- Dynamic functions ถูกประกาศตอน `init` เท่านั้น
- ถ้าสร้าง slug ใหม่แล้วต้องการเรียก dynamic function ใน request เดียวกัน อาจต้องใช้ helper API กลางแทน
- Query ผ่าน `meta_query_custom` สร้าง SQL ด้วย `EXISTS` subquery ไม่ได้ join table แบบ WordPress core meta query
- Import จะ insert แถวใหม่ ไม่ได้ merge/update แถวเดิม
- Export ตารางขนาดใหญ่มากอาจใช้ memory สูง เพราะโหลดข้อมูลทั้งตารางก่อน stream

## ตัวอย่าง workflow แนะนำ

1. สร้าง slug เช่น `product`
2. เพิ่ม schema ให้ key สำคัญ เช่น `price` เป็น `number`, `config` เป็น `json`
3. เพิ่ม index `idx_post_id_meta_key`
4. บันทึกข้อมูลผ่าน helper API:

```php
wppc_update_post_meta('product', 123, 'price', '199.50');
wppc_update_post_meta('product', 123, 'config', array(
    'enabled' => true,
    'stock' => 20,
));
```

5. Query ข้อมูล:

```php
$query = new WP_Query(array(
    'post_type' => 'product',
    'meta_query_custom_table' => 'product',
    'meta_query_custom' => array(
        array(
            'key' => 'price',
            'value' => '100',
            'compare' => '>=',
            'type' => 'NUMERIC',
        ),
    ),
));
```

6. Export backup ก่อน import/sync ข้อมูลจำนวนมาก

## Changelog

### 0.2.1

- ปรับ version เป็น `0.2.1`
- เพิ่มการตรวจ slug ที่ชนกับ dynamic function
- เพิ่มการตรวจความยาวชื่อตารางจริงตามข้อจำกัด MySQL
- ตรวจผลลัพธ์หลัง `dbDelta()` ก่อน register slug
- ป้องกัน unregister slug ถ้า `DROP TABLE` ล้มเหลว
- แก้ read/update/upsert ให้ใช้แถวล่าสุดตรงกัน
- แก้ `SHOW TABLES LIKE` ให้ escape wildcard
- ปรับ schema optional ให้ยอมรับค่าว่างได้
- ปรับ JSON schema ให้ normalize storage เป็น JSON
- ป้องกัน double serialize ตอน import/export
- เพิ่ม `meta_value_storage` ใน export/import
- ป้องกัน admin edit ค่า array/object แล้วชนิดข้อมูลเพี้ยน
- เพิ่ม validation slug ใน admin actions ที่เกี่ยวข้อง

### 0.2.0

- เพิ่มระบบหลายตารางตาม slug
- เพิ่ม admin pages
- เพิ่ม schema manager
- เพิ่ม index manager
- เพิ่ม import/export
- เพิ่ม sync batch กับ `wp_postmeta`
- เพิ่ม `meta_query_custom` สำหรับ `WP_Query`

## คำแนะนำก่อนใช้งานจริง

- ทดสอบ create/delete table บน staging ก่อน
- ทดสอบ import/export ด้วยข้อมูลจริงบางส่วน
- ทดสอบ sync ด้วย batch size เล็กก่อน
- เพิ่ม index ตาม query ที่ใช้งานจริง
- สำรองฐานข้อมูลก่อนลบตารางหรือ sync ข้อมูลจำนวนมาก
