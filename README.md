# WP Table Postmeta Custom

เวอร์ชัน: `1.1.0`

ปลั๊กอิน WordPress สำหรับสร้างและใช้งานตาราง `postmeta` แยกตาม `slug` เช่น `product`, `seo_data`, `campaign2026` เพื่อแยกกลุ่มข้อมูลออกจาก `wp_postmeta` หลัก และมีเครื่องมือหลังบ้านสำหรับจัดการตาราง, index, import/export และ sync ข้อมูล

ปลั๊กอินจะไม่สร้างตาราง custom อัตโนมัติหลัง activate ต้องสร้าง slug เองในหน้า admin ก่อนใช้งาน

## API หลัก

ปลั๊กอินใช้ helper API กลางชุดเดียว:

```php
wppc_get_post_meta()
wppc_update_post_meta()
wppc_delete_post_meta()
wppc_get_post_custom()
wppc_get_post_meta_batch()
wppc_update_post_meta_batch()
wppc_delete_post_meta_batch()
```

ค่า `meta_value` จะถูกเก็บและอ่านกลับเป็น string เสมอ ถ้าส่ง array/object เข้าไป ระบบจะแปลงเป็น JSON string ก่อนบันทึก

## WP_Query

ใช้ argument รูปแบบนี้เพื่อ query จากตาราง custom:

```php
meta_query_wppc-{table_slug}
```

ตัวอย่าง query จากตาราง `product`:

```php
$query = new WP_Query(array(
    'post_type' => 'post',
    'meta_query_wppc-product' => array(
        array(
            'key' => 'price',
            'value' => '100',
            'compare' => '>=',
            'type' => 'NUMERIC',
        ),
    ),
));
```

## ความสามารถหลัก

- สร้างตาราง custom postmeta ได้หลายตารางในรูปแบบ `{$wpdb->prefix}postmeta_<slug>`
- สร้างและลบตารางจากหน้า admin ได้
- จัดการข้อมูลในตารางได้จากหน้า admin: เพิ่ม, แก้ไข, ลบ, ค้นหาตาม `post_id` และ `meta_key`, แบ่งหน้า
- อ่าน/เขียน/ลบ meta ผ่าน helper API กลางชุด `wppc_*`
- บังคับ unique key ระหว่าง `post_id` และ `meta_key`
- Query post จากตาราง custom ผ่าน `WP_Query`
- ใช้ร่วมกับ `meta_query` ปกติของ WordPress ได้
- เพิ่ม/ลบ index จาก preset ได้
- Import/Export ข้อมูลเป็น JSON หรือ CSV
- Sync ข้อมูลกับ `wp_postmeta` ได้แบบ batch/cursor
- มี nonce และ capability check สำหรับ action หลังบ้าน

## ความต้องการระบบ

- WordPress ที่มี `$wpdb` และระบบ admin ปกติ
- PHP ที่รองรับ syntax แบบ WordPress plugin มาตรฐาน
- MySQL/MariaDB ที่รองรับ table/index ตามโครงสร้างของ WordPress
- ผู้ใช้งานหน้า admin ของปลั๊กอินต้องมี capability `manage_options`

## การติดตั้ง

1. วางไฟล์ปลั๊กอินไว้ในโฟลเดอร์ `wp-content/plugins/wp-table-postmeta-custom/`
2. เข้าเมนู `Plugins` ใน WordPress admin
3. กด `Activate`
4. ไปสร้าง slug ที่ `Tools > WP Postmeta Custom > รายการประเภทตาราง`

หลัง activate จะยังไม่มีตาราง `postmeta_<slug>` ใด ๆ จนกว่าจะสร้าง slug เอง

## เริ่มใช้งานเร็ว

1. ไปที่ `Tools > WP Postmeta Custom > รายการประเภทตาราง`
2. สร้าง slug เช่น `product`
3. ระบบจะสร้างตาราง:

```text
{$wpdb->prefix}postmeta_product
```

4. บันทึกข้อมูล:

```php
wppc_update_post_meta('product', 123, 'price', '199.50');
```

5. อ่านข้อมูล:

```php
$price = wppc_get_post_meta('product', 123, 'price');
```

6. Query post จากข้อมูลในตาราง `product`:

```php
$query = new WP_Query(array(
    'post_type' => 'post',
    'meta_query_wppc-product' => array(
        array(
            'key' => 'price',
            'value' => '100',
            'compare' => '>=',
            'type' => 'NUMERIC',
        ),
    ),
));
```

## แนวคิดหลัก

### Table slug

`slug` คือชื่อสั้น ๆ ที่ใช้ต่อท้ายชื่อตาราง custom

ถ้า slug คือ:

```text
product
```

ตารางจริงจะเป็น:

```text
{$wpdb->prefix}postmeta_product
```

### Helper API

การอ่าน/เขียน/ลบข้อมูลให้เรียกผ่าน helper กลาง และส่ง slug เป็น argument แรกเสมอ:

```php
wppc_update_post_meta('product', 123, 'color', 'blue');
$color = wppc_get_post_meta('product', 123, 'color');
wppc_delete_post_meta('product', 123, 'color');
```

### Unique Key

ทุกตาราง custom บังคับ unique key ระหว่าง:

```text
post_id + meta_key
```

ดังนั้น `wppc_update_post_meta('product', 123, 'price', '199.50')` จะเขียนไปยังแถวเดียวของ `post_id = 123` และ `meta_key = price` เสมอ

## โครงสร้างตาราง

ทุกตาราง custom ใช้โครงสร้างหลัก:

```sql
meta_id    bigint unsigned auto increment
post_id    bigint unsigned
meta_key   varchar(191)
meta_value longtext
```

index เริ่มต้น:

- `PRIMARY KEY (meta_id)`
- `UNIQUE KEY uniq_post_id_meta_key (post_id, meta_key)`
- `KEY meta_key (meta_key)`

## กติกา slug

slug ใช้สำหรับสร้างชื่อตาราง

กติกาที่อนุญาต:

- ต้องขึ้นต้นด้วยตัวอักษร `a-z`
- ใช้ได้เฉพาะ `a-z`, `0-9`, `_`
- ระบบจะแปลงเป็น lowercase
- ชื่อตารางจริงต้องไม่ยาวเกินข้อจำกัดของ MySQL

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

## เมนูหลังบ้าน

เมนูหลักอยู่ที่:

```text
Tools > WP Postmeta Custom
```

หน้าในระบบ:

- `ภาพรวม`: แสดงจำนวนตาราง, จำนวนแถวรวม และ version
- `รายการประเภทตาราง`: สร้างและลบตารางตาม slug
- `จัดการข้อมูลตาราง`: เพิ่ม/แก้ไข/ลบข้อมูล, index, import/export และ sync

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

ลบได้ทุก slug ที่สร้างไว้

เมื่อลบสำเร็จ ระบบจะ:

- `DROP TABLE` ตารางนั้น
- ลบ slug ออกจาก registry
- ลบ sync state ของ slug นั้น

ถ้า `DROP TABLE` ไม่สำเร็จ ระบบจะไม่ลบ registry/sync state

## Helper API

### อ่านค่า

```php
wppc_get_post_meta($table_slug, $post_id, $meta_key, $from_main = false);
```

ตัวอย่าง:

```php
$color = wppc_get_post_meta('product', 123, 'color');
```

`wppc_get_post_meta()` จะคืนค่า `meta_value` เป็น raw string จากฐานข้อมูลเสมอ ไม่มีการ `maybe_unserialize()` และไม่มีการ decode JSON อัตโนมัติ

ถ้า `$from_main = true` และไม่พบค่าในตาราง custom ระบบจะ fallback ไปอ่าน raw string จาก `wp_postmeta`

```php
$color = wppc_get_post_meta('product', 123, 'color', true);
```

### เพิ่มหรืออัปเดตค่า

```php
wppc_update_post_meta($table_slug, $post_id, $meta_key, $meta_value);
```

ตัวอย่าง:

```php
wppc_update_post_meta('product', 123, 'color', 'blue');
```

ถ้าตารางยังไม่มีอยู่ ระบบจะพยายามสร้างตารางก่อนบันทึก

ค่าที่ส่งเข้า `wppc_update_post_meta()` จะถูกแปลงก่อนเก็บดังนี้:

- string, number และ scalar อื่น ๆ จะถูก cast เป็น string
- `true` จะเก็บเป็น `1`
- `false` และ `null` จะเก็บเป็นค่าว่าง
- array/object จะถูกแปลงเป็น JSON string

### ลบค่า

```php
wppc_delete_post_meta($table_slug, $post_id, $meta_key, $meta_value = null);
```

ลบแถวของ key นั้น:

```php
wppc_delete_post_meta('product', 123, 'color');
```

ลบเฉพาะเมื่อค่าตรงกัน:

```php
wppc_delete_post_meta('product', 123, 'color', 'blue');
```

### อ่าน meta ทั้งหมดของโพสต์ (Batch Custom Meta)

```php
wppc_get_post_custom($table_slug, $post_id);
```

ตัวอย่าง:

```php
$all_meta = wppc_get_post_custom('product', 123);
// ได้ผลลัพธ์เป็น associative array: array('price' => '199.50', 'color' => 'blue', ...)
```

### อ่านเฉพาะชุด key ที่ระบุแบบ Batch

```php
wppc_get_post_meta_batch($table_slug, $post_id, array $meta_keys);
```

ตัวอย่าง:

```php
$meta = wppc_get_post_meta_batch('product', 123, array('price', 'color'));
// ได้ผลลัพธ์: array('price' => '199.50', 'color' => 'blue')
```

### เพิ่มหรืออัปเดตข้อมูลแบบ Batch (Transaction)

```php
wppc_update_post_meta_batch($table_slug, $post_id, array $meta_data);
```

ตัวอย่าง:

```php
$result = wppc_update_post_meta_batch('product', 123, array(
    'price' => '250.00',
    'color' => 'red',
    'sku'   => 'SKU-RED-123',
));
// $result = array('updated' => 3, 'keys' => array('price', 'color', 'sku'))
```

### ลบข้อมูลชุด key แบบ Batch

```php
wppc_delete_post_meta_batch($table_slug, $post_id, array $meta_keys);
```

ตัวอย่าง:

```php
$deleted_count = wppc_delete_post_meta_batch('product', 123, array('color', 'sku'));
// คืนค่าจำนวนแถวที่ถูกลบจริง
```

## WP_Query

ใช้ `meta_query_wppc-{table_slug}` เพื่อกรอง post ด้วย meta จากตาราง custom

### ตัวอย่างพื้นฐาน

```php
$query = new WP_Query(array(
    'post_type' => 'post',
    'meta_query_wppc-product' => array(
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
    'meta_query_wppc-product' => array(
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
    'meta_query_wppc-product' => array(
        array(
            'key' => 'color',
            'value' => 'blue',
            'compare' => '=',
        ),
    ),
));
```

### ใช้หลายตาราง custom พร้อมกัน

```php
$query = new WP_Query(array(
    'post_type' => 'post',
    'meta_query_wppc-product' => array(
        array(
            'key' => 'price',
            'value' => '100',
            'compare' => '>=',
            'type' => 'NUMERIC',
        ),
    ),
    'meta_query_wppc-seo_data' => array(
        array(
            'key' => 'indexable',
            'value' => '1',
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
    'meta_query_wppc-product' => array(
        array(
            'key' => 'price',
            'value' => '100',
            'compare' => '>=',
            'type' => 'NUMERIC',
        ),
    ),
));
```

## ค้นหาข้อมูลหลังบ้าน

หน้า `จัดการข้อมูลตาราง` มีช่องค้นหาแยกกัน:

- `post_id`: ค้นหาแบบตรงตัว
- `meta_key`: ค้นหาแบบ partial match ด้วย `LIKE`

ถ้ากรอกทั้งสองช่อง ระบบจะใช้เงื่อนไขแบบ `AND`

## Index Manager

ไปที่:

```text
จัดการข้อมูลตาราง > จัดการดัชนี (Index)
```

ตารางจะมี unique index `uniq_post_id_meta_key` อยู่แล้วและลบผ่านหน้า admin ไม่ได้

Preset ที่เพิ่มได้:

- `idx_meta_key_post_id`: `(meta_key, post_id)`
- `idx_post_id_meta_key_value`: `(post_id, meta_key, meta_value(191))`

คำแนะนำ:

- ถ้าค้นหาตาม `post_id + meta_key` ใช้ unique index ที่มีอยู่แล้ว
- ถ้าค้นหาตาม `meta_key` ก่อน แล้วค่อยกรอง `post_id` ให้เพิ่ม `idx_meta_key_post_id`
- ถ้ากรองค่า `meta_value` สั้น ๆ ด้วย ให้พิจารณา `idx_post_id_meta_key_value`

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

Import จะ upsert ตาม unique key `post_id + meta_key`

### Export JSON

JSON export จะส่งค่า raw string ใน `meta_value`

ตัวอย่าง:

```json
[
  {
    "meta_id": "1",
    "post_id": "123",
    "meta_key": "config",
    "meta_value": "{\"enabled\":true,\"limit\":10}"
  }
]
```

### Export CSV

CSV export จะส่งค่า raw string ใน `meta_value`

ตัวอย่าง header:

```csv
meta_id,post_id,meta_key,meta_value
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

ถ้า `meta_value` เป็น array/object ระบบจะแปลงเป็น JSON string ก่อนบันทึก

### Import CSV

ตัวอย่าง:

```csv
post_id,meta_key,meta_value
123,color,blue
123,size,large
```

CSV จะอ่านทุกค่าเป็น string ตามเนื้อหาในไฟล์

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
- sync จะ upsert ตาม unique key `post_id + meta_key`

ข้อควรระวัง:

- Sync เป็นการกดรันจากหน้า admin ยังไม่มี cron อัตโนมัติ
- ควรทดสอบบน staging ก่อน sync ข้อมูลจำนวนมาก

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

- ปลั๊กอินไม่สร้างตาราง custom อัตโนมัติ ต้องสร้าง slug ก่อน
- ตาราง custom ไม่ sync อัตโนมัติกับ `wp_postmeta`
- Query ผ่าน `meta_query_wppc-{table_slug}` สร้าง SQL ด้วย `EXISTS` subquery ไม่ได้ join table แบบ WordPress core meta query
- Export ตารางขนาดใหญ่มากอาจใช้ memory สูง เพราะโหลดข้อมูลทั้งตารางก่อน stream

## Workflow แนะนำ

1. สร้าง slug เช่น `product`
2. เพิ่ม index ที่ตรงกับ query จริง ถ้าต้องค้นหานอกเหนือจาก `post_id + meta_key`
3. บันทึกข้อมูลผ่าน helper API:

```php
wppc_update_post_meta('product', 123, 'price', '199.50');
wppc_update_post_meta('product', 123, 'config', array(
    'enabled' => true,
    'stock' => 20,
));
```

4. Query ข้อมูล:

```php
$query = new WP_Query(array(
    'post_type' => 'product',
    'meta_query_wppc-product' => array(
        array(
            'key' => 'price',
            'value' => '100',
            'compare' => '>=',
            'type' => 'NUMERIC',
        ),
    ),
));
```

5. Export backup ก่อน import/sync ข้อมูลจำนวนมาก

## Changelog

### 1.0.0

- ไม่สร้าง `postmeta_wppc` อัตโนมัติหลัง activate
- ใช้ helper API กลางชุด `wppc_*` เป็นมาตรฐานเดียวสำหรับอ่าน/เขียน/ลบ meta
- เปลี่ยน WP_Query argument เป็น `meta_query_wppc-{table_slug}`
- บังคับ unique key ระหว่าง `post_id` และ `meta_key`
- ตัดระบบ schema ต่อ `meta_key` ออก
- เก็บ `meta_value` เป็น string เสมอ และแปลง array/object เป็น JSON string ก่อนบันทึก
- `wppc_get_post_meta()` คืน raw string จากฐานข้อมูลเสมอ
- ตัด `meta_value_storage` ออกจาก import/export
- แยกช่องค้นหาหลังบ้านเป็น `post_id` และ `meta_key`

## คำแนะนำก่อนใช้งานจริง

- ทดสอบ create/delete table บน staging ก่อน
- ทดสอบ import/export ด้วยข้อมูลจริงบางส่วน
- ทดสอบ sync ด้วย batch size เล็กก่อน
- เพิ่ม index ตาม query ที่ใช้งานจริง
- สำรองฐานข้อมูลก่อนลบตารางหรือ sync ข้อมูลจำนวนมาก
