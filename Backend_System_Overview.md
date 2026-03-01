# GameBackend System Overview

## ระบบ Backend สำหรับเกม Unity

**เวอร์ชัน**: 1.0.0  
**ภาษา**: PHP 8.4+  
**เฟรมเวิร์ก**: Laravel 12.0  
**ฐานข้อมูล**: SQLite (รองรับ MySQL/PostgreSQL)  
**เป้าหมาย**: เกมแนว Gacha RPG สำหรับ Unity WebGL/Mobile

---

## 📋 สารบัญ

1. [ภาพรวมระบบ](#ภาพรวมระบบ)
2. [สถาปัตยกรรม](#สถาปัตยกรรม)
3. [ส่วนประกอบหลัก](#ส่วนประกอบหลัก)
4. [API Endpoints](#api-endpoints)
5. [ฐานข้อมูล](#ฐานข้อมูล)
6. [ความปลอดภัย](#ความปลอดภัย)
7. [การติดตั้ง](#การติดตั้ง)
8. [การพัฒนา](#การพัฒนา)

---

## 🎯 ภาพรวมระบบ

### วัตถุประสงค์หลัก
- ให้บริการ Authentication และ Authorization สำหรับผู้เล่น
- จัดการข้อมูล Save/Load ของผู้เล่น
- ให้บริการระบบ Gacha (สุ่มตัวละคร)
- จัดการความก้าวหน้าของผู้เล่น (Progression)
- รองรับการเชื่อมต่อกับเกม Unity

### คุณสมบัติเด่น
- ✅ **Authentication แบบหลายช่องทาง**: Email + Google OAuth
- ✅ **ระบบ Save/Load แบบ Cloud**: ข้อมูลไม่หายแม้เปลี่ยนอุปกรณ์
- ✅ **Gacha System สมจริง**: มี Pity System, Rate, Fallback
- ✅ **ความปลอดภัยสูง**: Rate Limiting, Input Validation, Transactions
- ✅ **Scalable Architecture**: ออกแบบมาเพื่อขยายระบบในอนาคต

---

## 🏗️ สถาปัตยกรรม

### เทคโนโลยีหลัก
```
Frontend (Unity) ←→ REST API ←→ Laravel Backend ←→ Database
```

### โครงสร้างโปรเจค
```
GameBackend/
├── app/
│   ├── Http/
│   │   ├── Controllers/     # API Controllers
│   │   └── Middleware/      # Security & Auth Middleware
│   ├── Models/              # Database Models
│   └── Mail/                # Email Templates
├── routes/
│   └── api.php              # API Endpoints
├── database/
│   ├── migrations/          # Database Schema
│   ├── seeders/             # Initial Data
│   └── data/                # Game Configuration
├── config/                  # System Configuration
└── resources/
    └── views/               # Email Templates
```

### การทำงานของระบบ
1. **ผู้เล่นล็อกอิน** → รับ Token จาก Backend
2. **เล่นเกม** → ส่งข้อมูลไปยัง Backend ผ่าน Token
3. **บันทึกข้อมูล** → Backend จัดการข้อมูลในฐานข้อมูล
4. **โหลดข้อมูล** → Backend ส่งข้อมูลกลับไปยังเกม

---

## 🔧 ส่วนประกอบหลัก

### 1. Authentication System

#### รูปแบบการล็อกอิน
- **Email + Password**: สำหรับผู้เล่นทั่วไป
- **Google OAuth**: สำหรับผู้เล่นที่ใช้ Google Account
- **Token-based**: ใช้ Laravel Sanctum (หมดอายุ 30 วัน)

#### ความปลอดภัย
- บังคับใช้ Email Gmail เท่านั้น
- Rate Limiting ป้องกัน Brute Force
- Password Reset ด้วย Email Verification
- Token Expiration และ Refresh

### 2. Game Data Management

#### ข้อมูลที่จัดการ
```json
{
  "playerData": {
    "playerName": "ชื่อผู้เล่น",
    "playerRank": 1,
    "coins": 5000,
    "gems": 100,
    "currentExp": 0,
    "maxTeamCost": 50,
    "currentEnergy": 240,
    "ownedCharacters": {},
    "ownedMaterials": {},
    "encounteredCharacterIds": []
  },
  "questData": {
    "questProgress": {}
  },
  "progressData": {
    "progressEntries": []
  },
  "allPresets": []
}
```

#### คุณสมบัติ
- **JSON Blob Storage**: จัดการข้อมูลซับซ้อนได้ดี
- **Race Condition Protection**: ใช้ Database Transactions
- **Data Validation**: ป้องกันข้อมูลเสียหาย
- **Energy System**: ฟื้นฟูตามเวลา

### 3. Gacha System

#### ระบบสุ่มตัวละคร
- **Rarity System**: N (2x), R, SR, SSR
- **Pull Options**: 1-pull และ 10-pull
- **Pity System**: 40 ครั้งรับประกัน SSR
- **Featured Characters**: มีตัวละครพิเศษที่รับประกันได้

#### อัตราการสุ่ม
- **SSR**: 3% (Featured รับประกันเมื่อถึง Pity)
- **SR**: 17%
- **R**: 80%

#### Fallback System
- ได้ตัวละครซ้ำ → ได้ Material แทน
- Material ใช้สำหรับอัพเกรดตัวละคร

### 4. Character Management

#### ข้อมูลตัวละคร
- **62 ตัวละคร** แบ่งเป็น 4 ระดับความหายาก
- **Pool Types**: Standard, Featured, Limited
- **Configuration**: จัดการผ่าน JSON File
- **Database Seeding**: อัพเดตข้อมูลได้ง่าย

---

## 🌐 API Endpoints

### Authentication Endpoints

#### 1. สมัครสมาชิก
```http
POST /api/register
Content-Type: application/json

{
  "email": "user@gmail.com",
  "password": "password123"
}
```
**Response**:
```json
{
  "token": "your-auth-token",
  "message": "Register Successful"
}
```

#### 2. เข้าสู่ระบบ
```http
POST /api/login
Content-Type: application/json

{
  "email": "user@gmail.com",
  "password": "password123"
}
```

#### 3. Google OAuth
```http
POST /api/auth/google/verify-token
Content-Type: application/json

{
  "access_token": "google-access-token"
}
```

### Game Data Endpoints

#### 1. บันทึกข้อมูล
```http
POST /api/save
Authorization: Bearer your-token
Content-Type: application/json

{
  "data": { /* game save data */ },
  "pity_count": 5
}
```

#### 2. โหลดข้อมูล
```http
GET /api/load
Authorization: Bearer your-token
```

### Gacha Endpoints

#### สุ่มตัวละคร
```http
POST /api/gacha/pull
Authorization: Bearer your-token
Content-Type: application/json

{
  "pullAmount": 10,
  "target_character_id": 401
}
```

---

## 🗄️ ฐานข้อมูล

### Tables Overview

#### 1. users
```sql
CREATE TABLE users (
  id INTEGER PRIMARY KEY,
  name VARCHAR(255),
  email VARCHAR(255) UNIQUE,
  password VARCHAR(255),
  google_id VARCHAR(255),
  avatar TEXT,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

#### 2. game_saves
```sql
CREATE TABLE game_saves (
  id INTEGER PRIMARY KEY,
  user_id INTEGER REFERENCES users(id),
  data TEXT,  -- JSON Blob
  pity_count INTEGER DEFAULT 0,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

#### 3. characters
```sql
CREATE TABLE characters (
  id INTEGER PRIMARY KEY,
  name VARCHAR(255),
  rarity VARCHAR(10),  -- N, R, SR, SSR
  pool_type VARCHAR(20), -- Standard, Featured, Limited
  rate_multiplier FLOAT DEFAULT 1.0,
  fallback_material_id INTEGER,
  fallback_amount INTEGER DEFAULT 10
);
```

### Database Relationships
- **User → GameSave**: One-to-One
- **User → Characters**: Many-to-Many (through ownedCharacters)
- **Characters → Materials**: Many-to-One (through fallback)

---

## 🔒 ความปลอดภัย

### 1. Rate Limiting
```php
// จำกัดการเข้าถึง API
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('auth', function (Request $request) {
    return Limit::perMinute(10)->by($request->ip());
});
```

### 2. Input Validation
- ตรวจสอบรูปแบบ Email (ต้องเป็น @gmail.com)
- ตรวจสอบความยาว Password
- ตรวจสอบค่า Currency (ไม่ให้ติดลบ)
- ตรวจสอบ JSON Format

### 3. Database Security
- **Transactions**: ป้องกัน Race Condition
- **Row Locking**: ป้องกันการเขียนทับพร้อมกัน
- **Foreign Key Constraints**: รักษาความสมบูรณ์ของข้อมูล

### 4. Authentication Security
- **Token Expiration**: 30 วัน
- **Token Revocation**: ลบ Token เก่าเมื่อ Login ใหม่
- **Password Hashing**: ใช้ bcrypt
- **Email Verification**: สำหรับ Google OAuth

---

## 🚀 การติดตั้ง

### ข้อกำหนดระบบ
- PHP 8.4+
- Composer
- Node.js (สำหรับ Frontend Build)
- Database (SQLite/MySQL/PostgreSQL)

### ขั้นตอนการติดตั้ง
```bash
# 1. Clone Repository
git clone https://github.com/Hoshi-GameDev/GameBackend.git
cd GameBackend

# 2. ติดตั้ง Dependencies
composer install
npm install

# 3. ตั้งค่า Environment
cp .env.example .env
php artisan key:generate

# 4. ตั้งค่า Database
# แก้ไข .env ตาม Database ที่ใช้

# 5. รัน Migration และ Seed
php artisan migrate --force
php artisan db:seed

# 6. Build Frontend
npm run build

# 7. เริ่ม Server
php artisan serve
```

### การตั้งค่า Google OAuth
1. สร้าง Project ใน Google Cloud Console
2. ตั้งค่า OAuth Consent Screen
3. สร้าง Credentials (OAuth Client ID)
4. ตั้งค่า `config/services.php` และ `.env`

---

## 🛠️ การพัฒนา

### เพิ่มตัวละครใหม่

#### 1. แก้ไข JSON Configuration
```json
{
  "id": 501,
  "characterName": "ชื่อตัวละครใหม่",
  "rarity": "SR",
  "poolType": "Standard",
  "rateMultiplier": 2.0,
  "fallbackMaterialId": 501,
  "fallbackAmount": 1
}
```

#### 2. อัพเดต Database
```bash
php artisan db:seed --class=CharactersSeeder
```

### เพิ่ม API Endpoint ใหม่

#### 1. สร้าง Controller
```bash
php artisan make:controller NewFeatureController
```

#### 2. เพิ่ม Route ใน `routes/api.php`
```php
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/new-feature', [NewFeatureController::class, 'handle']);
});
```

#### 3. สร้าง Model (ถ้าจำเป็น)
```bash
php artisan make:model NewModel -m
```

### Testing
```bash
# รัน Tests
php artisan test

# รัน Tests แบบเฉพาะ
php artisan test --filter=testFeatureName
```

---

## 📊 การขยายระบบ

### 1. เพิ่ม Feature ใหม่
- **Shop System**: ร้านค้าแลกเปลี่ยนไอเท็ม
- **Guild System**: ระบบกิลด์/ปาร์ตี้
- **PvP System**: ระบบต่อสู้ผู้เล่น
- **Event System**: กิจกรรมตามฤดูกาล

### 2. การ Scale
- **Load Balancing**: ใช้หลาย Server
- **Database Replication**: อ่าน/เขียนแยก
- **Caching**: Redis/Memcached
- **CDN**: สำหรับ Static Assets

### 3. Monitoring
- **Logging**: ใช้ Laravel Logging
- **Error Tracking**: Sentry/Flare
- **Performance**: Laravel Telescope
- **Uptime**: External Monitoring Tools

---

## 🤝 การมีส่วนร่วม

### รายงาน Bug
- ใช้ GitHub Issues
- แนบ Logs และ Steps to Reproduce

### ส่ง PR
1. Fork Repository
2. สร้าง Branch ใหม่
3. Commit Changes
4. ส่ง Pull Request

### สไตล์การเขียน Code
- ใช้ Laravel Best Practices
- เขียน Tests สำหรับ Feature ใหม่
- อัพเดต Documentation

---

## 📞 ติดต่อ

- **Repository**: [GitHub Link]
- **Documentation**: [Wiki Link]
- **Issues**: [Issues Link]
- **Email**: developer@gamebackend.com

---

## 📄 License

โปรเจคนี้อยู่ภายใต้ลิขสิทธิ์ MIT License - ดูรายละเอียดได้ที่ [LICENSE](LICENSE)

---

**Last Updated**: March 2026  
**Version**: 1.0.0