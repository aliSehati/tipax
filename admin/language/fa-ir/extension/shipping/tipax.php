<?php
// version
$_['OCM_VERSION']  = '1.2.0';
$_['text_version'] = 'نسخه : %s';

// Heading
$_['heading_title']    = 'تیپاکس';

// Text
$_['text_extension']   = 'افزونه‌ها';
$_['text_success']     = 'تنظیمات تیپاکس با موفقیت ذخیره شد!';
$_['text_edit']        = 'ویرایش تیپاکس';
$_['text_enabled']     = 'فعال';
$_['text_disabled']    = 'غیرفعال';
$_['text_all_zones']   = 'همه مناطق';
$_['text_none']        = 'هیچکدام';

// Tabs
$_['tab_general']   = 'تنظیمات کلی';
$_['tab_services']  = 'پارامترهای سرویس';
$_['tab_sender']    = 'آدرس فرستنده';
$_['tab_cities']    = 'شهرها';
$_['tab_wallet']    = 'کیف پول';
$_['tab_orders']    = 'سفارشات';
$_['tab_mapping']   = 'تطابق شهرها';
$_['tab_state_mapping'] = 'تطابق استان‌ها';

// Entries - API credentials
$_['entry_username']    = 'نام کاربری تیپاکس';
$_['entry_password']    = 'رمز عبور تیپاکس';
$_['entry_api_key']     = 'کلید API';
$_['entry_env']         = 'محیط اتصال API';
$_['text_env_test']     = 'محیط تست (Test)';
$_['text_env_prod']     = 'محیط اصلی (Production)';
$_['help_env']          = 'برای توسعه از محیط تست استفاده کنید. در محیط اصلی سفارشات واقعی ثبت می‌شود.';

// Entries - OpenCart common
$_['entry_tax_class']   = 'کلاس مالیات';
$_['entry_geo_zone']    = 'منطقه جغرافیایی';
$_['entry_status']      = 'وضعیت';
$_['entry_sort_order']  = 'ترتیب';

// Entries - Service required params
$_['entry_pack_type']        = 'نوع بسته‌بندی (PackType)';
$_['entry_payment_type']     = 'نوع پرداخت (PaymentType)';
// Removed from UI: pickup/distribution/service/label privacy (ثابت در کد)
$_['entry_default_weight_kg'] = 'وزن پیش‌فرض هر کالا (کیلوگرم)';
$_['entry_default_length_cm'] = 'طول پیش‌فرض (سانتی‌متر)';
$_['entry_default_width_cm']  = 'عرض پیش‌فرض (سانتی‌متر)';
$_['entry_default_height_cm'] = 'ارتفاع پیش‌فرض (سانتی‌متر)';

// Entries - Sender
$_['entry_sender_mode']          = 'روش تعیین آدرس فرستنده';
$_['entry_sender_saved_address'] = 'آدرس ذخیره‌شده فرستنده (دفترچه تیپاکس)';
$_['entry_sender_name']          = 'نام فرستنده';
$_['entry_sender_mobile']        = 'موبایل فرستنده';
$_['entry_sender_phone']         = 'تلفن فرستنده';
$_['entry_sender_full_address']  = 'آدرس کامل فرستنده';
$_['entry_sender_postal_code']   = 'کد پستی فرستنده';
$_['entry_sender_city_id']       = 'شهر فرستنده (CityId تیپاکس)';
$_['entry_sender_no']            = 'پلاک';
$_['entry_sender_unit']          = 'واحد';
$_['entry_sender_floor']         = 'طبقه';
$_['entry_sender_lat']           = 'عرض جغرافیایی';
$_['entry_sender_lng']           = 'طول جغرافیایی';

// Entries - Behavior
$_['entry_auto_submit']                           = 'ثبت خودکار سفارش در تیپاکس';
$_['entry_auto_submit_statuses']                  = 'وضعیت‌های ثبت خودکار';
$_['help_auto_submit_statuses']                   = 'وضعیت‌هایی که در صورت ثبت سفارش ، سفارش به صورت خودکار در تیپاکس ثبت شود.';
$_['entry_auto_submit_on_status_change']          = 'ثبت خودکار سفارش تیپاکس در صورت تغییر وضعیت سفارش';
$_['entry_auto_submit_on_status_change_statuses'] = 'وضعیت‌های ثبت خودکار در تغییر وضعیت';
$_['help_auto_submit_on_status_change_statuses']  = 'وضعیت‌هایی که در صورت تغییر سفارش به آن‌ها، سفارش به صورت خودکار به تیپاکس ارسال شود.';

// Wallet
$_['entry_wallet_charge']    = 'مبلغ شارژ (تومان)';

// Buttons
$_['button_save']           = 'ذخیره';
$_['button_cancel']         = 'انصراف';
$_['button_test_connection']= 'تست اتصال';
$_['button_check_wallet']   = 'بررسی موجودی';
$_['button_charge_wallet']  = 'شارژ کیف پول';
$_['button_sync_cities']    = 'همگام‌سازی شهرهای تیپاکس';
$_['button_load_addresses'] = 'بارگذاری دفترچه آدرس';
$_['button_manage_orders']  = 'مدیریت سفارشات';
$_['button_open_mapping']   = 'مدیریت تطابق شهرها';
$_['button_open_state_mapping'] = 'مدیریت تطابق استان‌ها';
$_['button_auto_match_states'] = 'تطابق خودکار استان‌ها';
$_['button_map_save']       = 'ذخیره تطابق';
$_['button_submit_to_tipax']= 'ارسال به تیپاکس';
$_['button_cancel_tipax']   = 'ابطال سفارش در تیپاکس';

// Text helpers
$_['text_saved_address'] = 'از دفترچه آدرس تیپاکس';
$_['text_map_address']   = 'انتخاب دستی/نقشه';
$_['text_services_hint'] = 'پارامترهای سرویس اصلی در کد ثابت شده‌اند. فقط نوع پرداخت و مقادیر پیش‌فرض وزن/ابعاد را تنظیم کنید.';
$_['text_sender_hint']   = 'اگر از دفترچه تیپاکس استفاده کنید، آدرس مبدا با آیدی دفترچه (senderAddressAndClient.addressId) ارسال می‌شود؛ در غیر این صورت از آدرس دستی استفاده می‌شود.';
$_['text_wallet_note']   = 'شارژ از طریق لینک پرداخت رسمی تیپاکس انجام می‌شود.';
$_['text_mapping_hint']  = 'شهرهای اوپن‌کارت را به شناسه شهرهای تیپاکس تطابق دهید تا قیمت‌گیری/ثبت سفارش دقیق انجام شود.';
$_['text_mapping_search']= 'جستجو در شهرها...';
$_['text_state_mapping_search']= 'جستجو در استان‌ها...';
$_['text_unmatched_warning'] = 'توجه: %d شهر تیپاکس هنوز با شهرهای اوپن‌کارت تطابق داده نشده‌اند. برای رفع، روی «مدیریت تطابق شهرها» کلیک کنید.';

// Help
$_['help_api_key']       = 'کلید اختصاصی از پنل تیپاکس.';
// Removed helps for fixed params in code
$_['help_default_weight_kg'] = 'اگر وزن محصول مشخص نباشد، این مقدار به ازای هر واحد کالا اعمال می‌شود.';
$_['help_default_dimensions'] = 'اگر ابعاد محصول مشخص نباشد، از این مقادیر استفاده می‌شود. واحد سانتی‌متر است.';
$_['help_auto_submit']   = 'در صورت فعال بودن، پس از ثبت سفارش در فروشگاه، سفارش به صورت خودکار در تیپاکس نیز ثبت می‌شود.';
// $_['help_payment_type'] = '۱۰: سمت فرستنده نقدی/اعتباری، ۲۰: پس‌کرایه، ۳۰: پرداخت در محل گیرنده، ۴۰: پرداخت در محل فرستنده، ۵۰: پرداخت از کیف پول.';
$_['help_payment_type'] = '۱۰: سمت فرستنده نقدی/اعتباری، ۲۰: پس‌کرایه، ۵۰: پرداخت از کیف پول.';
$_['help_pickup_type'] = '۱۰: جمع‌آوری از محل مشتری، ۲۰: تحویل در نمایندگی.';
$_['help_distribution_type'] = '۱۰: تحویل در محل مشتری، ۲۰: تحویل در نمایندگی.';

// Success / Info
$_['success_cities_synced'] = 'همگام‌سازی شهرها با موفقیت انجام شد: %d شهر.';
$_['success_connection']    = 'اتصال به API برقرار است!';
$_['success_addresses_loaded'] = 'دفترچه آدرس با موفقیت بارگذاری شد.';
$_['success_mapping_saved'] = 'تطابق شهرها ذخیره شد.';
$_['success_state_mapping_saved'] = 'تطابق استان‌ها ذخیره شد.';
$_['success_submitted']     = 'سفارش به تیپاکس ارسال شد.';
$_['success_cancelled']     = 'سفارش تیپاکس ابطال شد.';

// Errors
$_['error_permission']  = 'هشدار: شما اجازه ویرایش این ماژول را ندارید!';
$_['error_username']    = 'نام کاربری الزامی است!';
$_['error_password']    = 'رمز عبور الزامی است!';
$_['error_api_key']     = 'کلید API الزامی است!';
$_['error_connection']  = 'خطا در اتصال به API!';
$_['error_cities_sync'] = 'خطا در همگام‌سازی شهرها!';
$_['error_wallet_charge'] = 'مبلغ شارژ نامعتبر است!';
$_['error_sender_name']   = 'نام فرستنده الزامی است!';
$_['error_sender_mobile'] = 'موبایل فرستنده الزامی است!';
$_['error_sender_address']= 'آدرس فرستنده الزامی است!';
