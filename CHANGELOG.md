# Changelog

## 1.2.0

- เพิ่มแท็บค้นหาและจัดการ `wp_postmeta` หลักในหน้า Data Manager
- รองรับเพิ่ม แก้ไข และลบข้อมูลหลักทีละรายการผ่าน WordPress Metadata API
- ล็อก `post_id` ระหว่างแก้ไข และปิด bulk delete/truncate สำหรับตารางหลักทั้งใน UI และฝั่งเซิร์ฟเวอร์
- เพิ่ม source allowlist, object-level capability check และ regression tests สำหรับ PHP/AJAX/JavaScript

## 1.1.0

- เพิ่ม Batch Meta Helper API สำหรับอ่าน เพิ่ม/อัปเดต และลบ meta หลายรายการ พร้อม transaction สำหรับการอัปเดต
- เพิ่มการลบ custom postmeta อัตโนมัติเมื่อ post ถูกลบ เพื่อป้องกันข้อมูลค้างในตาราง
- ปรับหน้า admin ให้โหลดประเภทตารางและ Data Manager ผ่าน AJAX พร้อมค้นหา แบ่งหน้า และจัดการรายการแบบ CRUD
- เพิ่ม bulk delete, truncate และการค้นหาด้วย `meta_value` ใน Data Manager
- ปรับปรุง import/export CSV และ JSON ให้รองรับตาราง `wp_postmeta` หลัก พร้อมการประมวลผลแบบ transaction, chunk และตรวจขนาดไฟล์
- เพิ่ม developer hooks สำหรับการเปลี่ยนแปลง meta และวงจรชีวิตของตาราง
- เพิ่มการสร้าง index เริ่มต้นอัตโนมัติ และปรับปรุงการรองรับ BOM ของไฟล์ CSV
- ป้องกัน CSV formula injection ด้วยการ sanitize ค่าในเซลล์ก่อนส่งออก

## 1.0.0

- ไม่สร้าง `postmeta_wppc` อัตโนมัติหลัง activate
- ใช้ helper API กลางชุด `wppc_*` เป็นมาตรฐานเดียวสำหรับอ่าน/เขียน/ลบ meta
- เปลี่ยน WP_Query argument เป็น `meta_query_wppc-{table_slug}`
- บังคับ unique key ระหว่าง `post_id` และ `meta_key`
- ตัดระบบ schema ต่อ `meta_key` ออก
- เก็บ `meta_value` เป็น string เสมอ และแปลง array/object เป็น JSON string ก่อนบันทึก
- `wppc_get_post_meta()` คืน raw string จากฐานข้อมูลเสมอ
- ตัด `meta_value_storage` ออกจาก import/export
- แยกช่องค้นหาหลังบ้านเป็น `post_id` และ `meta_key`
