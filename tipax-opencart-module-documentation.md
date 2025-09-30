# مستندات ماژول تیپاکس برای OpenCart 3

## فهرست مطالب
- [معرفی](#معرفی)
- [نصب و راه‌اندازی](#نصب-و-راه‌اندازی)
- [تنظیمات اولیه](#تنظیمات-اولیه)
- [امکانات ماژول](#امکانات-ماژول)
- [مدیریت سفارشات](#مدیریت-سفارشات)
- [نقشه‌برداری شهرها](#نقشه‌برداری-شهرها)
- [تنظیمات پیشرفته](#تنظیمات-پیشرفته)
- [عیب‌یابی](#عیب‌یابی)
- [سوالات متداول](#سوالات-متداول)
- [API Reference](#api-reference)

---

## معرفی

ماژول تیپاکس برای OpenCart 3 یک افزونه کامل و حرفه‌ای است که امکان ادغام سیستم حمل و نقل تیپاکس را با فروشگاه آنلاین شما فراهم می‌کند.

### ویژگی‌های کلیدی:
- ✅ محاسبه خودکار هزینه حمل و نقل
- ✅ ثبت خودکار سفارشات در سیستم تیپاکس
- ✅ پیگیری مرسولات با کد رهگیری
- ✅ تولید و چاپ بارکد
- ✅ مدیریت کامل خطاها و لاگ‌ها
- ✅ نقشه‌برداری شهرها
- ✅ پشتیبانی از واحدهای مختلف وزن و اندازه
- ✅ تبدیل ارز خودکار (ریال/تومان)
- ✅ ابطال سفارشات (تکی و گروهی)
- ✅ سیستم retry برای سفارشات خطادار

### سیستم مورد نیاز:
- OpenCart 3.x
- PHP 7.0 یا بالاتر
- cURL extension
- JSON extension

---

## نصب و راه‌اندازی

### مرحله 1: آپلود فایل‌ها
فایل‌های زیر را در مسیرهای مربوطه آپلود کنید:

\`\`\`
admin/
├── controller/extension/shipping/tipax.php
├── model/extension/shipping/tipax.php
├── view/template/extension/shipping/
│   ├── tipax.twig
│   ├── tipax_orders.twig
│   ├── tipax_pending_orders.twig
│   └── tipax_city_mapping.twig
└── language/fa-ir/extension/shipping/tipax.php

catalog/
├── controller/extension/shipping/tipax.php
├── controller/extension/cron/tipax.php
├── model/extension/shipping/tipax.php
└── language/fa-ir/extension/shipping/tipax.php

system/
├── library/tipax.php
└── tipax_integration.ocmod.xml
\`\`\`

### مرحله 2: نصب OCMOD
1. به بخش `Extensions > Installer` بروید
2. فایل `system/tipax_integration.ocmod.xml` را آپلود کنید
3. به `Extensions > Modifications` بروید و `Refresh` کنید

### مرحله 3: فعال‌سازی ماژول
1. به `Extensions > Extensions` بروید
2. نوع را روی `Shipping` تنظیم کنید
3. ماژول تیپاکس را پیدا کرده و `Install` کنید
4. سپس `Edit` کنید تا وارد تنظیمات شوید

---

## تنظیمات اولیه

### اطلاعات API
برای استفاده از ماژول، نیاز به اطلاعات زیر از تیپاکس دارید:

\`\`\`
نام کاربری (Username): your_username
رمز عبور (Password): your_password
کلید API (API Key): your_api_key
\`\`\`

### تنظیمات پایه

#### 1. تنظیمات عمومی
- **وضعیت**: فعال/غیرفعال کردن ماژول
- **ترتیب**: اولویت نمایش در لیست روش‌های حمل و نقل

#### 2. تنظیمات ارسال خودکار
- **ارسال خودکار به تیپاکس**: فعال/غیرفعال
- **وضعیت‌های ارسال خودکار**: انتخاب وضعیت‌هایی که سفارش باید خودکار ثبت شود

#### 3. تنظیمات ارسال خودکار در تغییر وضعیت
- **ثبت خودکار هنگام تغییر وضعیت**: فعال/غیرفعال
- **وضعیت‌های مجاز**: انتخاب وضعیت‌هایی که تغییر به آن‌ها باعث ثبت خودکار شود

---

## امکانات ماژول

### 1. محاسبه هزینه حمل و نقل

#### ویژگی‌ها:
- محاسبه خودکار بر اساس وزن، ابعاد و مقصد
- پشتیبانی از تبدیل واحدها:
  - وزن: به کیلوگرم تبدیل می‌شود
  - ابعاد: به سانتی‌متر تبدیل می‌شود
- تبدیل خودکار ارز:
  - `TOM`, `IRT` → تومان (×10 برای تبدیل به ریال)
  - `RLS` → ریال

#### نحوه عملکرد:
1. سیستم وزن و ابعاد محصولات سبد خرید را محاسبه می‌کند
2. شهر مقصد را با شهرهای تیپاکس تطبیق می‌دهد
3. درخواست قیمت‌گیری به API تیپاکس ارسال می‌شود
4. قیمت دریافتی به ارز فروشگاه تبدیل و نمایش داده می‌شود

### 2. ثبت خودکار سفارشات

#### حالت اول: ثبت هنگام تکمیل سفارش
\`\`\`php
// تنظیمات مورد نیاز
shipping_tipax_auto_submit = 1
shipping_tipax_auto_submit_statuses = [1,2,5] // آرایه وضعیت‌ها
\`\`\`

#### حالت دوم: ثبت هنگام تغییر وضعیت
\`\`\`php
// تنظیمات مورد نیاز  
shipping_tipax_auto_submit_on_status_change = 1
shipping_tipax_auto_submit_on_status_change_statuses = [5,15] // آرایه وضعیت‌ها
\`\`\`

### 3. مدیریت سفارشات تیپاکس

#### مسیر دسترسی:
`Sales > Tipax Orders`

#### فیلترهای موجود:
- **همه**: تمام سفارشات تیپاکس
- **ارسال شده**: سفارشات موفق ثبت شده
- **خطادار**: سفارشات با خطا
- **در انتظار**: سفارشات ثبت نشده

#### اطلاعات نمایش داده شده:
- شماره سفارش فروشگاه
- اطلاعات مشتری (نام، تلفن)
- شهر گیرنده
- کدهای رهگیری
- وضعیت سفارش
- پیام خطا (در صورت وجود)
- تعداد تلاش‌های انجام شده

#### اقدامات قابل انجام:
- **تلاش مجدد**: برای سفارشات خطادار
- **ابطال سفارش**: لغو تکی سفارش
- **ابطال گروهی**: لغو چندین سفارش همزمان

### 4. کدهای رهگیری و بارکد

#### ویژگی‌ها:
- دریافت خودکار کدهای رهگیری از تیپاکس
- ذخیره هم `trackingCodes` و هم `trackingCodesWithTitles`
- تولید خودکار بارکد برای هر کد رهگیری
- امکان چاپ بارکد

#### نمایش در صفحه سفارش:
- کدهای رهگیری به صورت متن
- بارکد قابل کلیک برای چاپ
- دکمه کپی کردن کد رهگیری
- نمایش وضعیت سفارش تیپاکس

---

## نقشه‌برداری شهرها

### مسیر دسترسی:
`Extensions > Shipping > Tipax > City Mapping`

### عملکردها:

#### 1. همگام‌سازی شهرها
\`\`\`php
// دریافت لیست شهرهای تیپاکس
$cities = $this->tipax->citiesPlusState($token);
\`\`\`

#### 2. نمایش شهرهای بدون نقشه
- لیست شهرهای OpenCart که معادل تیپاکس ندارند
- امکان جستجو و فیلتر

#### 3. تطبیق دستی شهرها
- انتخاب شهر OpenCart
- انتخاب معادل آن در تیپاکس
- ذخیره نقشه‌برداری

#### 4. افزودن خودکار شهرهای ناشناخته
\`\`\`php
// افزودن تمام شهرهای تیپاکس به OpenCart
$this->model_extension_shipping_tipax->addUnmatchedCities();
\`\`\`

### جدول نقشه‌برداری:
\`\`\`sql
CREATE TABLE `tipax_city_mapping` (
  `opencart_city_id` int(11) NOT NULL,
  `tipax_city_id` int(11) NOT NULL,
  `opencart_city_name` varchar(128) NOT NULL,
  `tipax_city_name` varchar(128) NOT NULL,
  PRIMARY KEY (`opencart_city_id`)
);
\`\`\`

---

## تنظیمات پیشرفته

### تنظیمات فرستنده

#### حالت آدرس ذخیره شده:
\`\`\`php
shipping_tipax_sender_mode = 'saved'
shipping_tipax_sender_selected_address_id = 123 // شناسه آدرس در تیپاکس
\`\`\`

#### حالت آدرس دستی:
\`\`\`php
shipping_tipax_sender_mode = 'manual'
shipping_tipax_sender_city_id = 1 // شناسه شهر
shipping_tipax_sender_full_address = 'آدرس کامل فرستنده'
shipping_tipax_sender_postal_code = '1234567890'
shipping_tipax_sender_name = 'نام فرستنده'
shipping_tipax_sender_mobile = '09123456789'
shipping_tipax_sender_phone = '02112345678'
shipping_tipax_sender_lat = '35.6892'
shipping_tipax_sender_lng = '51.3890'
shipping_tipax_sender_no = '123'
shipping_tipax_sender_unit = '4'
shipping_tipax_sender_floor = '2'
\`\`\`

### پارامترهای پیش‌فرض سرویس

\`\`\`php
// نوع سرویس (پیش‌فرض: 2)
shipping_tipax_service_id = 2

// نوع پرداخت (پیش‌فرض: 10) 
shipping_tipax_payment_type = 10

// نوع دریافت (پیش‌فرض: 10)
shipping_tipax_pickup_type = 10

// نوع توزیع (پیش‌فرض: 10)
shipping_tipax_distribution_type = 10

// نوع بسته (پیش‌فرض: 20)
shipping_tipax_pack_type = 20

// فعال‌سازی حریم خصوصی برچسب (پیش‌فرض: true)
shipping_tipax_enable_label_privacy = true

// شناسه بسته‌بندی (پیش‌فرض: 3)
shipping_tipax_packing_id = 3

// شناسه محتوا (پیش‌فرض: 9)  
shipping_tipax_content_id = 9

// توضیحات (پیش‌فرض: خالی)
shipping_tipax_description = ''

// شناسه دفترچه مرسوله (پیش‌فرض: 0)
shipping_tipax_parcel_book_id = 0

// مرسوله غیرعادی (پیش‌فرض: false)
shipping_tipax_is_unusual = false
\`\`\`

### تبدیل واحدها

#### تبدیل وزن به کیلوگرم:
\`\`\`php
private function convertWeight($weight, $from_unit_id, $to_unit_id = null) {
    // واحدهای پشتیبانی شده:
    // 1: کیلوگرم (kg)
    // 2: گرم (g) 
    // 5: پاند (lb)
    // 6: اونس (oz)
}
\`\`\`

#### تبدیل طول به سانتی‌متر:
\`\`\`php
private function convertLength($length, $from_unit_id, $to_unit_id = null) {
    // واحدهای پشتیبانی شده:
    // 1: سانتی‌متر (cm)
    // 2: میلی‌متر (mm)
    // 3: اینچ (in)
    // 4: متر (m)
}
\`\`\`

---

## عیب‌یابی

### مشکلات رایج و راه‌حل‌ها

#### 1. خطای "وارد کردن وزن مرسوله الزامیست"
**علت**: وزن محصولات تنظیم نشده یا صفر است

**راه‌حل**:
\`\`\`php
// بررسی وزن محصولات
SELECT product_id, weight FROM oc_product WHERE weight = 0 OR weight IS NULL;

// تنظیم وزن پیش‌فرض
UPDATE oc_product SET weight = 0.5 WHERE weight = 0 OR weight IS NULL;
\`\`\`

#### 2. خطای "حداقل مقدار عرض مرسوله برای نوع کارتن سایز 1 10 سانتیمتر است"
**علت**: ابعاد محصولات کمتر از حد مجاز

**راه‌حل**:
\`\`\`php
// بررسی ابعاد محصولات
SELECT product_id, length, width, height FROM oc_product 
WHERE length < 10 OR width < 10 OR height < 10;

// تنظیم ابعاد حداقل
UPDATE oc_product SET 
    length = GREATEST(length, 10),
    width = GREATEST(width, 10), 
    height = GREATEST(height, 10)
WHERE length < 10 OR width < 10 OR height < 10;
\`\`\`

#### 3. خطای "شهر پشتیبانی نمی‌شود"
**راه‌حل**:
1. به `City Mapping` بروید
2. `Sync Cities` کنید
3. شهر مورد نظر را تطبیق دهید

#### 4. خطای احراز هویت (401)
**راه‌حل**:
- اطلاعات API را بررسی کنید
- اتصال اینترنت سرور را چک کنید
- کش توکن را پاک کنید:
\`\`\`php
$this->tipax->clearTokenCache();
\`\`\`

#### 5. خطای "سفارش تیپاکس نیست"
**علت**: کد حمل و نقل سفارش با `tipax.` شروع نمی‌شود

**راه‌حل**:
\`\`\`sql
-- بررسی کد حمل و نقل
SELECT order_id, shipping_code FROM oc_order WHERE shipping_code NOT LIKE 'tipax.%';
\`\`\`

### لاگ‌های سیستم

#### مسیر لاگ‌ها:
\`\`\`
system/storage/logs/error.log
\`\`\`

#### مشاهده لاگ‌های تیپاکس:
\`\`\`bash
tail -f system/storage/logs/error.log | grep Tipax
\`\`\`

#### نمونه لاگ خطا:
\`\`\`
2024-01-15 10:30:45 - PHP Notice: Tipax API Error: {"success":false,"error_code":400,"error_message":"درخواست نامعتبر: وارد کردن وزن مرسوله الزامیست"}
\`\`\`

### جدول لاگ خطاها

\`\`\`sql
-- ساختار جدول tipax_orders با فیلدهای لاگ
CREATE TABLE `tipax_orders` (
  `order_id` int(11) NOT NULL,
  `tipax_order_id` varchar(50) DEFAULT NULL,
  `tracking_codes` text,
  `tracking_codes_with_titles` text,
  `status` enum('pending','submitted','failed','cancelled') DEFAULT 'pending',
  `service_id` int(11) DEFAULT NULL,
  `payment_type` int(11) DEFAULT NULL,
  `error_message` text,
  `error_code` varchar(50) DEFAULT NULL,
  `retry_count` int(11) DEFAULT 0,
  `last_error_at` datetime DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_id`)
);
\`\`\`

---

## سوالات متداول

### س: آیا ماژول با تمام نسخه‌های OpenCart 3 سازگار است؟
**ج**: بله، ماژول با تمام نسخه‌های 3.x سازگار است و تست شده است.

### س: آیا امکان تست در محیط آزمایشی وجود دارد؟
**ج**: بله، می‌توانید URL پایه API را تغییر دهید:
\`\`\`php
// در فایل system/library/tipax.php
protected $api_base_url = 'https://testapi.tipax.ir'; // برای تست
\`\`\`

### س: چگونه می‌توانم ارز را از تومان به ریال تبدیل کنم؟
**ج**: ماژول به صورت خودکار تشخیص می‌دهد:
- کدهای `TOM`, `IRT`: تومان (×10 برای تبدیل به ریال)
- کد `RLS`: ریال (بدون تبدیل)

### س: آیا امکان ابطال سفارش وجود دارد؟
**ج**: بله، هم ابطال تکی و هم ابطال گروهی پشتیبانی می‌شود.

### س: چگونه بارکد چاپ کنم؟
**ج**: روی بارکد در صفحه سفارش کلیک کنید تا صفحه چاپ باز شود.

### س: چرا سفارش من خودکار ثبت نمی‌شود؟
**ج**: بررسی کنید:
1. ارسال خودکار فعال باشد
2. وضعیت سفارش در لیست وضعیت‌های مجاز باشد
3. سفارش از نوع تیپاکس باشد
4. شهر مقصد پشتیبانی شود

### س: چگونه می‌توانم سفارشات خطادار را مجدداً ارسال کنم؟
**ج**: 
1. به `Sales > Tipax Orders` بروید
2. فیلتر `خطادار` را انتخاب کنید
3. روی دکمه `تلاش مجدد` کلیک کنید

---

## API Reference

### کلاس Tipax Library

#### متدهای احراز هویت:
\`\`\`php
// دریافت توکن معتبر
public function getApiToken()

// پاک کردن کش توکن
public function clearTokenCache()
\`\`\`

#### متدهای قیمت‌گیری:
\`\`\`php
// قیمت‌گیری عادی
public function pricing($token, array $packageInputs)

// قیمت‌گیری با آدرس ذخیره شده
public function pricingWithOriginAddressId($token, array $packageInputs, $discountCode = null, $customerId = null)
\`\`\`

#### متدهای مدیریت سفارش:
\`\`\`php
// ثبت سفارش عادی
public function submitOrders($token, array $payload)

// ثبت سفارش با آدرس ذخیره شده  
public function submitWithPredefinedOrigin($token, array $payload)

// ابطال سفارش
public function cancelOrder($token, $tipax_order_id)
\`\`\`

#### متدهای کمکی:
\`\`\`php
// دریافت لیست شهرها
public function citiesPlusState($token)

// موجودی کیف پول
public function walletBalance($token)

// شارژ کیف پول
public function rechargeWallet($token, $amount, $callback_url)

// دفترچه آدرس‌ها
public function addressesBook($token)
\`\`\`

### نمونه Payload برای ثبت سفارش:

\`\`\`php
$payload = [
    'packages' => [[
        'origin' => [
            'cityId' => 1,
            'fullAddress' => 'آدرس کامل فرستنده',
            'postalCode' => '1234567890',
            'latitude' => '35.6892',
            'longitude' => '51.3890',
            'no' => '123',
            'unit' => '4', 
            'floor' => '2',
            'beneficiary' => [
                'fullName' => 'نام فرستنده',
                'mobile' => '09123456789',
                'phone' => '02112345678',
            ]
        ],
        'destination' => [
            'cityId' => 2,
            'fullAddress' => 'آدرس کامل گیرنده',
            'postalCode' => '0987654321',
            'beneficiary' => [
                'fullName' => 'نام گیرنده',
                'mobile' => '09987654321',
                'phone' => '02187654321',
            ]
        ],
        'weight' => 1.5, // کیلوگرم
        'packageValue' => 1000000, // ریال
        'length' => 30, // سانتی‌متر
        'width' => 20,  // سانتی‌متر  
        'height' => 10, // سانتی‌متر
        'packingId' => 3,
        'packageContentId' => 9,
        'packType' => 20,
        'description' => 'توضیحات مرسوله',
        'serviceId' => 2,
        'enableLabelPrivacy' => true,
        'paymentType' => 10,
        'pickupType' => 10,
        'distributionType' => 10,
        'cod' => 0,
        'cashAmount' => 0,
        'parcelBookId' => 0,
        'isUnusual' => false,
    ]],
    'traceCode' => 'OC-12345',
    'secondaryTraceCode' => '12345'
];
\`\`\`

### پاسخ موفق API:

\`\`\`php
{
    "orderId": "TIP123456789",
    "trackingCodes": ["1234567890", "0987654321"],
    "trackingCodesWithTitles": [
        {
            "title": "کد رهگیری اصلی",
            "code": "1234567890"
        },
        {
            "title": "کد رهگیری فرعی", 
            "code": "0987654321"
        }
    ]
}
\`\`\`

### پاسخ خطا API:

\`\`\`php
{
    "success": false,
    "error_code": 400,
    "error_message": "وارد کردن وزن مرسوله الزامیست",
    "raw_response": {
        "code": 400,
        "body": "وارد کردن وزن مرسوله الزامیست, حداقل مقدار عرض مرسوله برای نوع کارتن سایز 1 10 سانتیمتر است .",
        "error": ""
    }
}
\`\`\`

---

## تاریخچه نسخه‌ها

### نسخه 1.0.0 (2024-01-01)
- انتشار اولیه ماژول
- امکانات پایه محاسبه و ثبت سفارش
- پشتیبانی از API تیپاکس v3

### نسخه 1.1.0 (2024-01-15)
- اضافه شدن سیستم لاگ خطاها
- بهبود مدیریت سفارشات
- افزودن ابطال گروهی
- بهبود مدیریت توکن‌ها

### نسخه 1.2.0 (2024-02-01)
- اضافه شدن نقشه‌برداری شهرها
- بهبود تبدیل واحدها
- افزودن تنظیمات خودکار
- پشتیبانی از آدرس‌های ذخیره شده

### نسخه 1.3.0 (2024-02-15)
- اضافه شدن سیستم retry برای خطاها
- بهبود نمایش خطاها در پنل ادمین
- افزودن تولید و چاپ بارکد
- بهبود تبدیل ارز (تومان/ریال)

### نسخه 1.4.0 (2024-03-01)
- اضافه شدن ثبت خودکار در تغییر وضعیت
- بهبود رابط کاربری
- افزودن loading indicator
- بهبود copy to clipboard

---

## مجوز و پشتیبانی

### مجوز استفاده:
این ماژول تحت مجوز MIT منتشر شده است.

### پشتیبانی فنی:
برای دریافت پشتیبانی فنی:
1. ابتدا این مستندات را مطالعه کنید
2. لاگ‌های خطا را بررسی کنید  
3. تنظیمات API را چک کنید
4. در صورت نیاز با تیم پشتیبانی تماس بگیرید

### گزارش باگ:
برای گزارش باگ یا درخواست ویژگی جدید، لطفاً موارد زیر را ارائه دهید:
- نسخه OpenCart
- نسخه PHP
- توضیح کامل مشکل
- لاگ‌های مربوطه
- مراحل بازتولید مشکل

---

*این مستندات برای ماژول تیپاکس OpenCart 3 نسخه 1.4.0 تهیه شده است.*
*آخرین بروزرسانی: 2024-03-01*
