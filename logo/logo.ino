#include "neust_logo.h"
#include "time.h"
#include <Adafruit_GFX.h>
#include <Adafruit_PN532.h>
#include <Adafruit_ST7789.h>
#include <FFat.h>
#include <HTTPClient.h>
#include <LittleFS.h>
#include <SD.h>
#include <SPI.h>
#include <SPIFFS.h>
#include <TJpg_Decoder.h>
#include <WiFi.h>

// Keep old ILI9341 color names mapped to ST7789-compatible values
#define ILI9341_BLACK 0x0000
#define ILI9341_WHITE 0xFFFF
#define ILI9341_CYAN 0x07FF
#define ILI9341_YELLOW 0xFFE0
#define ILI9341_GREEN 0x07E0
#define ILI9341_RED 0xF800
#define ILI9341_ORANGE 0xFC00
#define ILI9341_DARKGREY 0x7BEF
#define NEUST_GOLD 0xFCA0
#define NEUST_NAVY 0x11B3

// =====================================================
// CONFIGURATION
// =====================================================

// ---------------- PN532 SPI ----------------
#define PN532_SS 10

// Shared SPI:
// MOSI = GPIO 11
// SCK  = GPIO 12
// MISO = GPIO 13

// ---------------- Buzzer / RGB ----------------
#define BUZZER 6
#define RGB_R_PIN 16
#define RGB_G_PIN 17
#define RGB_B_PIN 18

// ---------------- TFT ----------------
#define TFT_CS 7
#define TFT_RST 3
#define TFT_DC 2

#define SCREEN_WIDTH 320
#define SCREEN_HEIGHT 240

// ---------------- SD CARD ----------------
// MicroSD CS pin on TFT display module (shares MOSI=11, SCK=12, MISO=13)
#define SD_CS 4

// ---------------- RFID ----------------
#define NAME_BLOCK 4
#define SCAN_COOLDOWN 500

// ---------------- Network ----------------
#define WIFI_TIMEOUT 5000
#define HTTP_TIMEOUT 3000
#define MAX_RETRIES 3

// =====================================================
// GLOBALS
// =====================================================

Adafruit_PN532 nfc(PN532_SS);

Adafruit_ST7789 tft = Adafruit_ST7789(TFT_CS, TFT_DC, TFT_RST);

uint8_t nfcKey[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};

// =====================================================
// WIFI CONFIGURATION
// =====================================================

const char *ssid = "LW-INTERNET SERVICES";

const char *password = "eeeeeeee";

// =====================================================
// SERVER
// =====================================================

const char *serverName = "http://192.168.100.65/neust_gatepass/scan.php";

const char *photoBase =
    "http://192.168.100.65/neust_gatepass/get_photo.php?file=";

// =====================================================
// NTP & REALTIME CLOCK (RTC) CONFIGURATION
// =====================================================

const char *ntpServer = "pool.ntp.org";

const long gmtOffset_sec = 28800; // UTC+8 (Philippines Standard Time)

const int daylightOffset_sec = 0;

// Set to true for 24-hour clock (13:45:00), false for 12-hour AM/PM (01:45:00
// PM)
#define USE_24HOUR_CLOCK false

// Forward declarations
bool isWiFiConnected();

// =====================================================
// SYSTEM STATE
// =====================================================

volatile bool showResultScreen = false;
volatile bool isScanning = false;
volatile bool isRegistrationMode = false;
volatile bool readyScreenDrawn = false;

// Filesystem pointers (auto-detected at boot)
fs::FS *logoFS = nullptr;
fs::FS *storageFS = &FFat;
bool sdCardMounted = false;

String registrationName = "";

bool tftInitialized = false;

unsigned long resultStartTime = 0;
unsigned long lastScanTime = 0;

// =====================================================
// OFFLINE SCAN COOLDOWN
// =====================================================
// Prevents the same card from logging twice within
// OFFLINE_COOLDOWN_MS milliseconds (default 10 seconds).

#define OFFLINE_COOLDOWN_MS 10000UL

String lastOfflineUid = "";
unsigned long lastOfflineTime = 0;

// =====================================================
// TFT MUTEX
// =====================================================
//
// Prevents multiple FreeRTOS tasks from writing to
// the TFT simultaneously.
//

SemaphoreHandle_t tftMutex;

// =====================================================
// RFID QUEUE
// =====================================================

struct ScanPacket {
  char uid[32];
  char cardName[24];
  char photo[64];
};

QueueHandle_t rfidQueue;

// =====================================================
// OFFLINE STUDENT STRUCT & FORWARD DECLARATION
// =====================================================

struct OfflineStudentInfo {
  bool found = false;
  String name = "";
  String course = "";
  String status = "ACTIVE";
  String photo = "";
};

OfflineStudentInfo getOfflineStudent(const String &targetUid);

// =====================================================
// HTTP
// =====================================================

WiFiClient client;
HTTPClient http;

// =====================================================
// HELPER: TFT LOCK
// =====================================================

bool lockTFT(uint32_t timeout = 100) {
  if (tftMutex == NULL)
    return false;

  return xSemaphoreTake(tftMutex, pdMS_TO_TICKS(timeout)) == pdTRUE;
}

void unlockTFT() {
  if (tftMutex != NULL) {
    xSemaphoreGive(tftMutex);
  }
}

// =====================================================
// RGB LED
// =====================================================

inline void setColor(int r, int g, int b) {
  analogWrite(RGB_R_PIN, r);
  analogWrite(RGB_G_PIN, g);
  analogWrite(RGB_B_PIN, b);
}

inline void ledIdle() { setColor(0, 0, 255); }

inline void ledScan() { setColor(255, 60, 0); }

inline void ledOK() { setColor(0, 255, 0); }

inline void ledERR() { setColor(255, 0, 0); }

// =====================================================
// BUZZER
// =====================================================

inline void beep(int freq, int duration) { tone(BUZZER, freq, duration); }

void beepOK() {
  beep(2000, 80);
  delay(100);

  beep(2500, 80);
  delay(100);

  beep(3000, 100);
}

void beepERR() {
  for (int i = 0; i < 2; i++) {
    beep(1000, 80);
    delay(100);
  }
}

void beepReady() {
  beep(1500, 60);
  delay(80);

  beep(2000, 60);
  delay(80);
}

void beepBye() {
  beep(1800, 80);
  delay(100);

  beep(1400, 80);
  delay(100);

  beep(1000, 120);
}

// =====================================================
// TFT CENTER TEXT
// =====================================================

void tftCenterText(const String &text, int y, uint8_t sz, uint16_t color) {
  tft.setTextSize(sz);

  tft.setTextColor(color, ILI9341_BLACK);

  int16_t charW = 6 * sz;

  int16_t x = (SCREEN_WIDTH - ((int16_t)text.length() * charW)) / 2;

  if (x < 0)
    x = 0;

  tft.setCursor(x, y);

  tft.println(text);
}

// =====================================================
// REAL-TIME CLOCK (RTC) HELPERS
// =====================================================

bool getLocalTimeSafe(struct tm *info) {
  if (!info)
    return false;
  time_t now;
  time(&now);
  // Timestamps below 1700000000 (~Nov 2023) indicate time not yet synced from
  // NTP
  if (now < 1700000000) {
    return false;
  }
  localtime_r(&now, info);
  return true;
}

bool isTimeSynced() {
  time_t now;
  time(&now);
  return (now >= 1700000000);
}

String getFormattedTime() {
  struct tm ti;
  if (!getLocalTimeSafe(&ti)) {
    return "--:--:--";
  }

  char buf[16];
  if (USE_24HOUR_CLOCK) {
    snprintf(buf, sizeof(buf), "%02d:%02d:%02d", ti.tm_hour, ti.tm_min,
             ti.tm_sec);
  } else {
    int h = ti.tm_hour % 12;
    if (h == 0)
      h = 12;
    const char *ampm = (ti.tm_hour >= 12) ? "PM" : "AM";
    snprintf(buf, sizeof(buf), "%02d:%02d:%02d %s", h, ti.tm_min, ti.tm_sec,
             ampm);
  }
  return String(buf);
}

String getFormattedDate() {
  struct tm ti;
  if (!getLocalTimeSafe(&ti)) {
    if (isWiFiConnected())
      return "Syncing Time...";
    else
      return "Offline Mode";
  }

  const char *days[] = {"Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"};
  const char *months[] = {"Jan", "Feb", "Mar", "Apr", "May", "Jun",
                          "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"};

  char buf[32];
  snprintf(buf, sizeof(buf), "%s, %s %02d, %04d", days[ti.tm_wday],
           months[ti.tm_mon], ti.tm_mday, ti.tm_year + 1900);
  return String(buf);
}

String getFormattedDateTimeStamp() {
  struct tm ti;
  if (!getLocalTimeSafe(&ti)) {
    return "";
  }

  const char *months[] = {"Jan", "Feb", "Mar", "Apr", "May", "Jun",
                          "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"};

  char buf[40];
  if (USE_24HOUR_CLOCK) {
    snprintf(buf, sizeof(buf), "%s %02d, %04d | %02d:%02d:%02d",
             months[ti.tm_mon], ti.tm_mday, ti.tm_year + 1900, ti.tm_hour,
             ti.tm_min, ti.tm_sec);
  } else {
    int h = ti.tm_hour % 12;
    if (h == 0)
      h = 12;
    const char *ampm = (ti.tm_hour >= 12) ? "PM" : "AM";
    snprintf(buf, sizeof(buf), "%s %02d, %04d | %02d:%02d:%02d %s",
             months[ti.tm_mon], ti.tm_mday, ti.tm_year + 1900, h, ti.tm_min,
             ti.tm_sec, ampm);
  }
  return String(buf);
}

// =====================================================
// TFT INITIALIZATION
// =====================================================

bool initTFT() {
  Serial.println("Initializing TFT...");

  tft.init(240, 320);

  // Landscape
  tft.setRotation(1);

  tft.invertDisplay(false);

  tft.fillScreen(ILI9341_BLACK);

  tftInitialized = true;

  Serial.println("TFT initialized.");

  return true;
}

// =====================================================
// CENTER TEXT SCREEN
// =====================================================

void centerText(const String &line1, const String &line2 = "") {
  if (!tftInitialized) {
    Serial.println("TFT: " + line1 + " - " + line2);

    return;
  }

  if (!lockTFT(200))
    return;

  tft.fillScreen(ILI9341_BLACK);

  if (line2.isEmpty()) {
    tftCenterText(line1, SCREEN_HEIGHT / 2 - 16, 3, ILI9341_WHITE);
  } else {
    tftCenterText(line1, SCREEN_HEIGHT / 2 - 30, 3, ILI9341_WHITE);

    tftCenterText(line2, SCREEN_HEIGHT / 2 + 10, 2, ILI9341_CYAN);
  }

  unlockTFT();
}

// =====================================================
// JPEG CALLBACK FOR TJpg_Decoder
// =====================================================

bool tft_output(int16_t x, int16_t y, uint16_t w, uint16_t h,
                uint16_t *bitmap) {
  if (y >= tft.height())
    return 0;

  tft.drawRGBBitmap(x, y, bitmap, w, h);

  return 1;
}

// =====================================================
// DRAW LOGO
// =====================================================
// Renders the NEUST university logo.
// Priority 1: Reads "/logo.jpg" from mounted filesystem (LittleFS, FFat,
// SPIFFS)
//             with dynamic scaling for any resolution.
// Priority 2: Falls back to embedded PROGMEM byte array (neust_logo_jpg)
//             so the logo ALWAYS displays even without filesystem upload.

bool drawLogo(int16_t targetX = -1, int16_t targetY = -1, uint16_t maxW = 120,
              uint16_t maxH = 120) {
  if (!tftInitialized)
    return false;

  TJpgDec.setCallback(tft_output);

  // 1. Try to load from flash filesystem (/logo.jpg)
  if (logoFS != nullptr && logoFS->exists("/logo.jpg")) {
    uint16_t w = 0, h = 0;
    if (TJpgDec.getFsJpgSize(&w, &h, "/logo.jpg", *logoFS) == 0 && w > 0 &&
        h > 0) {
      uint8_t scale = 1;
      if (w / 8 >= maxW || h / 8 >= maxH)
        scale = 8;
      else if (w / 4 >= maxW || h / 4 >= maxH)
        scale = 4;
      else if (w / 2 >= maxW || h / 2 >= maxH)
        scale = 2;
      else
        scale = 1;

      uint16_t drawnW = w / scale;
      uint16_t drawnH = h / scale;

      int16_t x =
          (targetX >= 0) ? targetX : (int16_t)((SCREEN_WIDTH - drawnW) / 2);
      int16_t y =
          (targetY >= 0) ? targetY : (int16_t)((SCREEN_HEIGHT - drawnH) / 2);

      TJpgDec.setJpgScale(scale);
      if (TJpgDec.drawFsJpg(x, y, "/logo.jpg", *logoFS) == 0) {
        Serial.printf(
            "[LOGO] Rendered from filesystem (%dx%d, scale 1/%d at %d,%d)\n", w,
            h, scale, x, y);
        return true;
      } else {
        Serial.println("[LOGO] drawFsJpg failed, falling back to PROGMEM...");
      }
    }
  }

  // 2. Fallback to embedded PROGMEM byte array
  uint16_t w = 0, h = 0;
  if (TJpgDec.getJpgSize(&w, &h, neust_logo_jpg, neust_logo_jpg_len) == 0 &&
      w > 0 && h > 0) {
    uint8_t scale = 1;
    if (w / 8 >= maxW || h / 8 >= maxH)
      scale = 8;
    else if (w / 4 >= maxW || h / 4 >= maxH)
      scale = 4;
    else if (w / 2 >= maxW || h / 2 >= maxH)
      scale = 2;
    else
      scale = 1;

    uint16_t drawnW = w / scale;
    uint16_t drawnH = h / scale;

    int16_t x =
        (targetX >= 0) ? targetX : (int16_t)((SCREEN_WIDTH - drawnW) / 2);
    int16_t y =
        (targetY >= 0) ? targetY : (int16_t)((SCREEN_HEIGHT - drawnH) / 2);

    TJpgDec.setJpgScale(scale);
    if (TJpgDec.drawJpg(x, y, neust_logo_jpg, neust_logo_jpg_len) == 0) {
      Serial.printf(
          "[LOGO] Rendered from PROGMEM (%dx%d, scale 1/%d at %d,%d)\n", w, h,
          scale, x, y);
      return true;
    }
  }

  Serial.println("[LOGO] Failed to render logo.");
  return false;
}

// =====================================================
// BOOT SCREEN
// =====================================================

void showBootScreen(const String &statusLine) {
  if (!tftInitialized)
    return;
  if (!lockTFT(300))
    return;

  tft.fillScreen(ILI9341_BLACK);

  // University Logo
  drawLogo(-1, 25, 100, 100);

  // Title
  tftCenterText("NEUST GATEPASS", 140, 2, ILI9341_YELLOW);
  tft.drawFastHLine(30, 162, SCREEN_WIDTH - 60, ILI9341_CYAN);

  // Status
  tftCenterText(statusLine, 178, 2, ILI9341_WHITE);
  tftCenterText("RFID / NFC System Initializing...", 210, 1, ILI9341_DARKGREY);

  unlockTFT();
}

void updateBootStatus(const String &statusLine) {
  if (!tftInitialized)
    return;
  if (!lockTFT(150))
    return;

  tft.fillRect(0, 174, SCREEN_WIDTH, 26, ILI9341_BLACK);
  tftCenterText(statusLine, 178, 2, ILI9341_CYAN);

  unlockTFT();
}

// =====================================================
// READY SCREEN
// =====================================================

void showReady() {
  if (!tftInitialized)
    return;

  if (showResultScreen)
    return;

  if (isScanning)
    return;

  static unsigned long lastBlink = 0;
  static bool blinkState = true;
  bool stateChanged = false;

  if (millis() - lastBlink >= 450) {
    blinkState = !blinkState;
    lastBlink = millis();
    stateChanged = true;
  }

  static int lastClockSec = -1;
  static int lastClockDay = -1;
  static bool lastClockSynced = false;

  struct tm ti;
  bool currentSynced = getLocalTimeSafe(&ti);
  int currentSec = currentSynced ? ti.tm_sec : ((millis() / 1000) % 60);
  int currentDay = currentSynced ? ti.tm_yday : -1;
  bool secondChanged =
      (currentSec != lastClockSec) || (currentSynced != lastClockSynced);

  // 1. Initial draw or redraw when returning from scan / greeting
  if (!readyScreenDrawn) {
    if (!lockTFT(100))
      return;

    tft.fillScreen(ILI9341_BLACK);

    if (isRegistrationMode) {
      tftCenterText("REGISTRATION MODE", 8, 2, ILI9341_ORANGE);

      tft.drawFastHLine(20, 26, SCREEN_WIDTH - 40, ILI9341_ORANGE);

      drawLogo(-1, 30, 110, 110);

      tftCenterText(registrationName.isEmpty() ? "Tap New Card"
                                               : registrationName,
                    154, 2, ILI9341_WHITE);
    } else {
      // Top University Header
      tftCenterText("NEUST GATEPASS", 8, 2, ILI9341_YELLOW);

      tft.drawFastHLine(24, 26, SCREEN_WIDTH - 48, ILI9341_CYAN);

      // Center Logo
      drawLogo(-1, 30, 110, 110);

      // Prompt
      tftCenterText("PLEASE TAP CARD", 154, 2, ILI9341_GREEN);
    }

    // --- Real-Time Clock Panel Container ---
    tft.drawRoundRect(16, 176, SCREEN_WIDTH - 32, 58, 6, ILI9341_DARKGREY);

    // Render initial time and date inside clock container
    tftCenterText(getFormattedTime(), 182, 2, ILI9341_CYAN);
    tftCenterText(getFormattedDate(), 204, 1, ILI9341_YELLOW);

    // System status badge inside clock container
    if (isWiFiConnected()) {
      tftCenterText("RFID ACTIVE  |  WIFI ONLINE", 218, 1, ILI9341_DARKGREY);
    } else {
      tftCenterText("RFID ACTIVE  |  OFFLINE MODE", 218, 1, ILI9341_ORANGE);
    }

    lastClockSec = currentSec;
    lastClockDay = currentDay;
    lastClockSynced = currentSynced;

    readyScreenDrawn = true;
    unlockTFT();
    return;
  }

  // 2. Flicker-free prompt blinking (updates ONLY prompt area, leaves logo and
  // clock steady)
  if (stateChanged && !isRegistrationMode) {
    if (!lockTFT(50))
      return;

    tft.fillRect(0, 152, SCREEN_WIDTH, 20, ILI9341_BLACK);

    if (blinkState) {
      tftCenterText("PLEASE TAP CARD", 154, 2, ILI9341_GREEN);
    }

    unlockTFT();
  }

  // 3. Flicker-free Real-Time Clock update (updates ONLY time/date area every
  // second)
  if (secondChanged) {
    if (!lockTFT(50))
      return;

    // Clear time row
    tft.fillRect(20, 180, SCREEN_WIDTH - 40, 20, ILI9341_BLACK);

    // Draw new time
    tftCenterText(getFormattedTime(), 182, 2, ILI9341_CYAN);

    // If date or sync state changed, refresh date and status row
    if (currentDay != lastClockDay || currentSynced != lastClockSynced) {
      tft.fillRect(20, 202, SCREEN_WIDTH - 40, 26, ILI9341_BLACK);

      tftCenterText(getFormattedDate(), 204, 1, ILI9341_YELLOW);

      if (isWiFiConnected()) {
        tftCenterText("RFID ACTIVE  |  WIFI ONLINE", 218, 1, ILI9341_DARKGREY);
      } else {
        tftCenterText("RFID ACTIVE  |  OFFLINE MODE", 218, 1, ILI9341_ORANGE);
      }

      lastClockDay = currentDay;
      lastClockSynced = currentSynced;
    }

    lastClockSec = currentSec;
    unlockTFT();
  }
}

// =====================================================
// SCAN ANIMATION
// =====================================================

void showScanAnimation() {
  if (!tftInitialized)
    return;

  if (!isScanning)
    return;

  if (showResultScreen)
    return;

  readyScreenDrawn = false;

  static int step = 0;
  static unsigned long lastUpdate = 0;

  if (millis() - lastUpdate >= 60) {
    step = (step + 1) % 8;
    lastUpdate = millis();
  }

  if (!lockTFT(50))
    return;

  tft.fillScreen(ILI9341_BLACK);

  tftCenterText("SCANNING", 60, 3, ILI9341_YELLOW);

  int cx = SCREEN_WIDTH / 2;

  int cy = SCREEN_HEIGHT / 2 + 30;

  for (int i = 0; i < 8; i++) {
    float angle = i * PI / 4.0f;

    int x = cx + (int)(24 * cos(angle));

    int y = cy + (int)(24 * sin(angle));

    uint16_t col = (i == step) ? ILI9341_YELLOW : ILI9341_DARKGREY;

    tft.fillCircle(x, y, (i == step) ? 6 : 3, col);
  }

  unlockTFT();
}

// =====================================================
// WIFI STATUS
// =====================================================

bool isWiFiConnected() { return WiFi.status() == WL_CONNECTED; }

// =====================================================
// PHOTO DOWNLOAD
// =====================================================

void downloadAndShowPhoto(const String &photoFilename, int16_t photoX,
                          int16_t photoY) {
  if (photoFilename.isEmpty())
    return;

  if (!isWiFiConnected())
    return;

  String url = String(photoBase) + photoFilename;

  Serial.printf("[PHOTO] Downloading: %s\n", url.c_str());

  WiFiClient photoClient;
  HTTPClient photoHttp;

  photoHttp.begin(photoClient, url);

  photoHttp.setTimeout(4000);

  int code = photoHttp.GET();

  if (code != 200) {
    Serial.printf("[PHOTO] HTTP error: %d\n", code);

    photoHttp.end();

    return;
  }

  int contentLen = photoHttp.getSize();

  if (contentLen <= 0 || contentLen > 80000) {
    Serial.printf("[PHOTO] Bad content length: %d\n", contentLen);

    photoHttp.end();

    return;
  }

  uint8_t *buf = nullptr;

  if (psramFound()) {
    buf = (uint8_t *)ps_malloc(contentLen);
  }

  if (!buf) {
    buf = (uint8_t *)malloc(contentLen);
  }

  if (!buf) {
    Serial.println("[PHOTO] Alloc failed");

    photoHttp.end();

    return;
  }

  WiFiClient *stream = photoHttp.getStreamPtr();

  int read = 0;

  unsigned long t0 = millis();

  while (read < contentLen && millis() - t0 < 5000) {
    int avail = stream->available();

    if (avail > 0) {
      int chunk = stream->readBytes(buf + read, min(avail, contentLen - read));

      read += chunk;
    } else {
      delay(1);
    }
  }

  photoHttp.end();

  if (read != contentLen) {
    Serial.printf("[PHOTO] Incomplete read: %d/%d\n", read, contentLen);

    free(buf);

    return;
  }

  Serial.printf("[PHOTO] Got %d bytes in %lums - decoding...\n", read,
                millis() - t0);

  TJpgDec.setJpgScale(1);

  TJpgDec.setCallback(tft_output);

  TJpgDec.drawJpg(photoX, photoY, buf, (uint32_t)read);

  // Auto-cache to SD Card / storageFS for offline use
  if (storageFS != nullptr) {
    String localPath = "/photos/" + photoFilename;
    if (!storageFS->exists("/photos")) {
      storageFS->mkdir("/photos");
    }
    if (!storageFS->exists(localPath)) {
      File cacheFile = storageFS->open(localPath, FILE_WRITE);
      if (cacheFile) {
        cacheFile.write(buf, read);
        cacheFile.close();
        Serial.printf("[CACHE] Saved photo to storage: %s\n",
                      localPath.c_str());
      }
    }
  }

  free(buf);

  Serial.println("[PHOTO] Rendered.");
}

// =====================================================
// RENDER OR DOWNLOAD PHOTO
// =====================================================
// First checks local storage (SD Card / Flash). If found, renders
// instantly offline. Otherwise downloads over WiFi and caches to SD.

void renderOrDownloadPhoto(const String &photoFilename, int16_t photoX,
                           int16_t photoY) {
  if (photoFilename.isEmpty()) {
    drawLogo(-1, photoY, 120, 120);
    return;
  }

  // 1. Try local storage (SD Card / Flash)
  String localPath = "/photos/" + photoFilename;
  if (storageFS != nullptr && storageFS->exists(localPath)) {
    TJpgDec.setJpgScale(1);
    TJpgDec.setCallback(tft_output);
    if (TJpgDec.drawFsJpg(photoX, photoY, localPath.c_str(), *storageFS) == 0) {
      Serial.printf("[PHOTO] Loaded from local storage: %s\n",
                    localPath.c_str());
      return;
    }
  }

  // 2. Fall back to WiFi download (and auto-cache)
  if (isWiFiConnected()) {
    downloadAndShowPhoto(photoFilename, photoX, photoY);
  } else {
    // Offline placeholder: NEUST Logo
    drawLogo(-1, photoY, 120, 120);
  }
}

// =====================================================
// OFFLINE STUDENT LOOKUP (students.csv on SD / Flash)
// =====================================================

OfflineStudentInfo getOfflineStudent(const String &targetUid) {
  OfflineStudentInfo res;
  if (storageFS == nullptr || !storageFS->exists("/students.csv")) {
    return res;
  }

  File file = storageFS->open("/students.csv", FILE_READ);
  if (!file) {
    return res;
  }

  while (file.available()) {
    String line = file.readStringUntil('\n');
    line.trim();
    if (line.isEmpty() || line.startsWith("UID"))
      continue;

    // Expected format: UID,"Name","Course",Status,Photo
    int c1 = line.indexOf(',');
    if (c1 == -1)
      continue;

    String uid = line.substring(0, c1);
    uid.trim();
    uid.replace("\"", "");

    if (uid.equalsIgnoreCase(targetUid)) {
      res.found = true;
      String remainder = line.substring(c1 + 1);

      // Name (handles quotes)
      if (remainder.startsWith("\"")) {
        int endQ = remainder.indexOf('"', 1);
        if (endQ != -1) {
          res.name = remainder.substring(1, endQ);
          remainder = remainder.substring(endQ + 1);
          if (remainder.startsWith(","))
            remainder = remainder.substring(1);
        }
      } else {
        int nextC = remainder.indexOf(',');
        if (nextC != -1) {
          res.name = remainder.substring(0, nextC);
          remainder = remainder.substring(nextC + 1);
        } else {
          res.name = remainder;
          remainder = "";
        }
      }
      res.name.trim();

      // Course (handles quotes)
      if (remainder.startsWith("\"")) {
        int endQ = remainder.indexOf('"', 1);
        if (endQ != -1) {
          res.course = remainder.substring(1, endQ);
          remainder = remainder.substring(endQ + 1);
          if (remainder.startsWith(","))
            remainder = remainder.substring(1);
        }
      } else {
        int nextC = remainder.indexOf(',');
        if (nextC != -1) {
          res.course = remainder.substring(0, nextC);
          remainder = remainder.substring(nextC + 1);
        } else {
          res.course = remainder;
          remainder = "";
        }
      }
      res.course.trim();

      // Status and Photo
      int nextC = remainder.indexOf(',');
      if (nextC != -1) {
        res.status = remainder.substring(0, nextC);
        res.photo = remainder.substring(nextC + 1);
      } else {
        res.status = remainder;
      }
      res.status.trim();
      res.photo.trim();
      break;
    }
  }

  file.close();
  return res;
}

// =====================================================
// GREETING SCREEN
// =====================================================

void showGreeting(const String &status, const String &name,
                  const String &photoFilename = "") {
  if (!tftInitialized) {
    Serial.println("Greeting: " + status + " - " + name);

    return;
  }

  uint16_t accentColor = ILI9341_WHITE;

  bool showPhoto = false;

  if (status == "WELCOME") {
    accentColor = ILI9341_GREEN;

    showPhoto = true;
  } else if (status == "GOODBYE") {
    accentColor = ILI9341_CYAN;

    showPhoto = true;
  } else if (status == "CHECKING IN") {
    accentColor = ILI9341_YELLOW;
  } else if (status == "SAVED" || status == "OFFLINE") {
    accentColor = ILI9341_CYAN;

    showPhoto = true;
  } else if (status == "REGISTERED") {
    accentColor = ILI9341_GREEN;
  } else if (status == "NOT" || status == "ALREADY" || status == "BLOCKED" ||
             status == "ERROR" || status == "FAILED") {
    accentColor = ILI9341_RED;
  }

  // -------------------------------------------------
  // LOCK TFT
  // -------------------------------------------------

  if (!lockTFT(500))
    return;

  readyScreenDrawn = false;

  // ALWAYS CLEAR SCREEN FIRST
  tft.fillScreen(ILI9341_BLACK);

  // -------------------------------------------------
  // PHOTO SCREEN
  // -------------------------------------------------

  if (showPhoto) {
    tftCenterText(status, 8, 3, accentColor);

    if (!photoFilename.isEmpty()) {
      // Photo is 160 x 160 (checks SD first, then server)
      renderOrDownloadPhoto(photoFilename, 80, 36);
    } else {
      // Default placeholder: NEUST Logo
      drawLogo(-1, 36, 120, 120);
    }

    if (!name.isEmpty()) {
      tftCenterText(name, 202, 2, ILI9341_WHITE);
    }

    String ts = getFormattedDateTimeStamp();
    if (!ts.isEmpty()) {
      tftCenterText(ts, 224, 1, ILI9341_YELLOW);
    }
  }

  // -------------------------------------------------
  // TEXT SCREEN
  // -------------------------------------------------

  else {
    tftCenterText(status, SCREEN_HEIGHT / 2 - 45, 3, accentColor);

    if (!name.isEmpty()) {
      tftCenterText(name, SCREEN_HEIGHT / 2 + 10, 2, ILI9341_WHITE);
    }

    String ts = getFormattedDateTimeStamp();
    if (!ts.isEmpty()) {
      tftCenterText(ts, SCREEN_HEIGHT / 2 + 38, 1, ILI9341_YELLOW);
    }
  }

  unlockTFT();
}

// =====================================================
// SPECIAL ERROR SCREEN
// =====================================================

void showErrorScreen(const String &line1, const String &line2) {
  if (!tftInitialized)
    return;

  readyScreenDrawn = false;

  if (!lockTFT(500))
    return;

  // COMPLETE CLEAR
  tft.fillScreen(ILI9341_BLACK);

  tftCenterText(line1, 65, 4, ILI9341_RED);

  if (!line2.isEmpty()) {
    tftCenterText(line2, 125, 3, ILI9341_RED);
  }

  unlockTFT();
}

// =====================================================
// NFC GLOBAL UID
// =====================================================

static uint8_t _uid[7];
static uint8_t _uidLen = 0;

// =====================================================
// READ CARD NAME
// =====================================================

String readCardName() {
  if (!nfc.mifareclassic_AuthenticateBlock(_uid, _uidLen, NAME_BLOCK, 0,
                                           nfcKey)) {
    return "";
  }

  uint8_t buffer[16];

  if (!nfc.mifareclassic_ReadDataBlock(NAME_BLOCK, buffer)) {
    return "";
  }

  String name = "";

  for (uint8_t i = 0; i < 16 && buffer[i] != 0; i++) {
    if (isPrintable(buffer[i])) {
      name += (char)buffer[i];
    }
  }

  name.trim();

  return name;
}

// =====================================================
// WRITE CARD NAME
// =====================================================

bool writeCardName(const String &name) {
  if (!nfc.mifareclassic_AuthenticateBlock(_uid, _uidLen, NAME_BLOCK, 0,
                                           nfcKey)) {
    return false;
  }

  uint8_t data[16] = {0};

  uint8_t len = min(name.length(), (size_t)16);

  for (uint8_t i = 0; i < len; i++) {
    data[i] = name[i];
  }

  return nfc.mifareclassic_WriteDataBlock(NAME_BLOCK, data);
}

// =====================================================
// GET UID
// =====================================================

String getUID() {
  String uid = "";

  for (uint8_t i = 0; i < _uidLen; i++) {
    if (_uid[i] < 0x10) {
      uid += "0";
    }

    uid += String(_uid[i], HEX);
  }

  uid.toUpperCase();

  return uid;
}

// =====================================================
// WIFI CONNECT
// =====================================================

void connectWiFi() {
  centerText("CONNECTING", "WiFi");

  WiFi.begin(ssid, password);

  unsigned long start = millis();

  while (WiFi.status() != WL_CONNECTED && millis() - start < WIFI_TIMEOUT) {
    delay(250);

    centerText("CONNECTING",
               "WiFi" + String((millis() / 500) % 4 == 0 ? "" : "..."));
  }

  if (WiFi.status() == WL_CONNECTED) {
    beepReady();

    centerText("WiFi", "CONNECTED");

    delay(1000);

    configTime(gmtOffset_sec, daylightOffset_sec, ntpServer, "time.google.com",
               "time.windows.com");

    Serial.println("WiFi connected. Real-Time Clock syncing with NTP...");

    Serial.print("IP: ");

    Serial.println(WiFi.localIP());
  } else {
    beepERR();

    centerText("WiFi", "FAILED");

    delay(1000);
  }
}

// =====================================================
// OFFLINE STORAGE
// =====================================================

const char *OFFLINE_FILE = "/offline_scans.txt";

// =====================================================
// SAVE OFFLINE SCAN
// =====================================================

void saveOfflineScan(const String &uid, unsigned long ts) {
  if (storageFS == nullptr)
    return;

  File file = storageFS->open(OFFLINE_FILE, FILE_APPEND);

  if (file) {
    file.println(uid + "," + String(ts));

    file.close();

    Serial.printf("[OFFLINE] Buffered scan: %s at ts=%lu\n", uid.c_str(), ts);
  } else {
    Serial.println("[OFFLINE] Failed to open storage file!");
  }
}

// =====================================================
// SYNC OFFLINE SCANS
// =====================================================

void syncOfflineScans() {
  if (!isWiFiConnected())
    return;

  if (storageFS == nullptr)
    return;

  if (!storageFS->exists(OFFLINE_FILE))
    return;

  File file = storageFS->open(OFFLINE_FILE, FILE_READ);

  if (!file)
    return;

  Serial.println("[OFFLINE] Syncing stored scans...");

  String remaining = "";

  int syncedCount = 0;

  while (file.available()) {
    String line = file.readStringUntil('\n');

    line.trim();

    if (line.isEmpty())
      continue;

    int commaIdx = line.indexOf(',');

    if (commaIdx == -1)
      continue;

    String oUid = line.substring(0, commaIdx);

    String oTs = line.substring(commaIdx + 1);

    http.begin(client, serverName);

    http.addHeader("Content-Type", "application/x-www-form-urlencoded");

    http.setTimeout(HTTP_TIMEOUT);

    int code = http.POST("uid=" + oUid + "&ts=" + oTs);

    http.end();

    if (code > 0) {
      syncedCount++;
    } else {
      remaining += line + "\n";
    }

    vTaskDelay(50 / portTICK_PERIOD_MS);
  }

  file.close();

  storageFS->remove(OFFLINE_FILE);

  if (!remaining.isEmpty()) {
    File remFile = storageFS->open(OFFLINE_FILE, FILE_WRITE);

    if (remFile) {
      remFile.print(remaining);

      remFile.close();
    }
  }

  if (syncedCount > 0) {
    Serial.printf("[OFFLINE] Successfully synced %d scans.\n", syncedCount);
  }
}

// =====================================================
// FILESYSTEM MOUNTING
// =====================================================
// Auto-detects LittleFS, FFat, and SPIFFS.
// Checks where "/logo.jpg" is located and configures storageFS for offline
// scans.

void mountFilesystems() {
  Serial.println("[FS] Initializing filesystems...");

  // 0. Try SD Card (MicroSD slot on TFT display)
  pinMode(SD_CS, OUTPUT);
  digitalWrite(SD_CS, HIGH);

  if (SD.begin(SD_CS)) {
    Serial.println("[FS] SD Card mounted successfully.");
    storageFS = &SD;
    sdCardMounted = true;
    if (SD.exists("/logo.jpg")) {
      logoFS = &SD;
      Serial.println("[FS] Found /logo.jpg on SD Card.");
    }
    if (SD.exists("/students.csv")) {
      Serial.println(
          "[FS] Found /students.csv on SD Card - Offline database ready.");
    }
  } else {
    Serial.println(
        "[FS] SD Card not detected or mount failed. Using internal flash.");
    sdCardMounted = false;
  }

  // 1. Try LittleFS (default for modern Arduino IDE ESP32 data uploader)
  if (LittleFS.begin(false)) {
    Serial.println("[FS] LittleFS mounted.");
    if (logoFS == nullptr && LittleFS.exists("/logo.jpg")) {
      logoFS = &LittleFS;
      Serial.println("[FS] Found /logo.jpg in LittleFS.");
    }
  } else {
    Serial.println(
        "[FS] LittleFS mount skipped (not formatted or unavailable).");
  }

  // 2. Try FFat (supports read/write offline scans)
  if (FFat.begin(false)) {
    Serial.println("[FS] FFat mounted.");
    storageFS = &FFat;
    if (logoFS == nullptr && FFat.exists("/logo.jpg")) {
      logoFS = &FFat;
      Serial.println("[FS] Found /logo.jpg in FFat.");
    }
  } else {
    Serial.println(
        "[FS] FFat read mount failed. Initializing with format on fail...");
    if (FFat.begin(true)) {
      Serial.println("[FS] FFat mounted (formatted).");
      storageFS = &FFat;
      if (logoFS == nullptr && FFat.exists("/logo.jpg")) {
        logoFS = &FFat;
        Serial.println("[FS] Found /logo.jpg in FFat.");
      }
    } else {
      Serial.println("[FS] FFat initialization failed!");
      if (LittleFS.begin(false)) {
        storageFS = &LittleFS;
      }
    }
  }

  // 3. Try SPIFFS (legacy uploader support)
  if (logoFS == nullptr && SPIFFS.begin(false)) {
    Serial.println("[FS] SPIFFS mounted.");
    if (SPIFFS.exists("/logo.jpg")) {
      logoFS = &SPIFFS;
      Serial.println("[FS] Found /logo.jpg in SPIFFS.");
    }
  }

  if (logoFS != nullptr) {
    Serial.println("[FS] /logo.jpg will be loaded from flash filesystem.");
  } else {
    Serial.println("[FS] /logo.jpg not found on filesystem. Embedded PROGMEM "
                   "logo will be used.");
  }
}

// =====================================================
// RFID TASK
// =====================================================

void rfidTask(void *pv) {
  for (;;) {
    if (millis() - lastScanTime < SCAN_COOLDOWN) {
      vTaskDelay(10 / portTICK_PERIOD_MS);

      continue;
    }

    _uidLen = 0;

    if (!nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, _uid, &_uidLen, 100)) {
      vTaskDelay(10 / portTICK_PERIOD_MS);

      continue;
    }

    lastScanTime = millis();

    // =================================================
    // REGISTRATION MODE
    // =================================================

    if (isRegistrationMode) {
      String uid = getUID();

      Serial.print("Card UID: ");

      Serial.println(uid);

      if (writeCardName(registrationName)) {
        ledOK();

        beepOK();

        showGreeting("REGISTERED", registrationName);

        delay(1500);
      } else {
        ledERR();

        beepERR();

        showErrorScreen("FAILED", "TRY AGAIN");

        delay(1500);
      }

      isRegistrationMode = false;

      continue;
    }

    // =================================================
    // NORMAL SCAN
    // =================================================

    String uid = getUID();

    String cName = readCardName();

    Serial.print("UID: ");

    Serial.println(uid);

    Serial.print("Card Name: ");

    Serial.println(cName);

    ScanPacket pkt;

    memset(&pkt, 0, sizeof(ScanPacket));

    strncpy(pkt.uid, uid.c_str(), sizeof(pkt.uid) - 1);

    strncpy(pkt.cardName, cName.c_str(), sizeof(pkt.cardName) - 1);

    xQueueSend(rfidQueue, &pkt, portMAX_DELAY);

    vTaskDelay(100 / portTICK_PERIOD_MS);
  }
}

// =====================================================
// SERVER TASK
// =====================================================

void serverTask(void *pv) {
  ScanPacket pkt;

  char uidBuffer[32];

  for (;;) {
    // Wait for RFID scan
    if (xQueueReceive(rfidQueue, &pkt, 2500 / portTICK_PERIOD_MS)) {
      // -------------------------------------------------
      // START SCAN STATE
      // -------------------------------------------------

      isScanning = true;

      showResultScreen = false;

      ledScan();

      String uidStr = String(pkt.uid);

      String cardNameStr = String(pkt.cardName);

      String photoFilename = String(pkt.photo);

      // -------------------------------------------------
      // IMMEDIATE CARD NAME
      // -------------------------------------------------

      if (!cardNameStr.isEmpty()) {
        showGreeting("CHECKING IN", cardNameStr);

        beep(2200, 40);
      }

      // -------------------------------------------------
      // OFFLINE
      // -------------------------------------------------

      if (!isWiFiConnected()) {
        time_t now = 0;

        time(&now);

        // IMPORTANT:
        // Result screen remains protected
        isScanning = true;

        showResultScreen = true;

        // -------------------------------------------------
        // OFFLINE COOLDOWN CHECK (10 seconds)
        // -------------------------------------------------
        // Prevents the same card from logging attendance twice
        // within OFFLINE_COOLDOWN_MS without a WiFi server.

        unsigned long nowMs = millis();

        if (lastOfflineUid == uidStr &&
            (nowMs - lastOfflineTime) < OFFLINE_COOLDOWN_MS) {
          unsigned long remaining =
              (OFFLINE_COOLDOWN_MS - (nowMs - lastOfflineTime)) / 1000;

          ledERR();
          beepERR();

          showErrorScreen("ALREADY", "SCANNED");

          delay(2000);

          showResultScreen = false;
          isScanning = false;
          ledIdle();
          delay(200);
          continue;
        }

        // Lookup student in offline database (students.csv on SD)
        OfflineStudentInfo offlineStudent = getOfflineStudent(uidStr);

        if (!offlineStudent.found) {
          ledERR();
          beepERR();
          showErrorScreen("NOT", "REGISTERED");
          delay(2000);
        } else if (offlineStudent.status == "BLOCKED") {
          ledERR();
          beepERR();
          showErrorScreen("BLOCKED", offlineStudent.name);
          delay(2000);
        } else if (offlineStudent.photo.isEmpty()) {
          // SECURITY: Reject student if no photo is registered
          ledERR();
          beepERR();
          showErrorScreen("NO PHOTO", "NOT ALLOWED");
          delay(2500);
        } else {
          // Authorized: record cooldown, save, and display
          lastOfflineUid = uidStr;
          lastOfflineTime = nowMs;

          saveOfflineScan(uidStr, (unsigned long)now);

          ledOK();
          beepOK();

          showGreeting("OFFLINE", offlineStudent.name, offlineStudent.photo);

          delay(2000);
        }

        showResultScreen = false;

        isScanning = false;

        ledIdle();

        delay(200);

        continue;
      }

      // -------------------------------------------------
      // PREPARE UID
      // -------------------------------------------------

      strncpy(uidBuffer, pkt.uid, sizeof(uidBuffer));

      uidBuffer[sizeof(uidBuffer) - 1] = '\0';

      // -------------------------------------------------
      // ONLINE COOLDOWN CHECK (10 seconds)
      // -------------------------------------------------
      // Same 10-second guard as offline mode. Blocks duplicate
      // taps before hitting the server — saves HTTP round-trips
      // and gives instant feedback.

      {
        unsigned long nowMs = millis();

        if (lastOfflineUid == uidStr &&
            (nowMs - lastOfflineTime) < OFFLINE_COOLDOWN_MS) {
          ledERR();

          beepERR();

          isScanning = true;
          showResultScreen = true;

          showErrorScreen("ALREADY", "SCANNED");

          delay(2000);

          showResultScreen = false;
          isScanning = false;
          ledIdle();
          delay(200);
          continue;
        }
      }

      // -------------------------------------------------
      // HTTP CONNECTION
      // -------------------------------------------------

      http.begin(client, serverName);

      http.addHeader("Content-Type", "application/x-www-form-urlencoded");

      http.setTimeout(HTTP_TIMEOUT);

      // -------------------------------------------------
      // HTTP RETRIES
      // -------------------------------------------------

      int code = -1;

      for (int retry = 0; retry < MAX_RETRIES && code <= 0; retry++) {
        Serial.printf("[HTTP] Scan attempt %d/%d\n", retry + 1, MAX_RETRIES);

        code = http.POST("uid=" + String(uidBuffer));

        if (code <= 0 && retry < MAX_RETRIES - 1) {
          Serial.printf("HTTP Error: %s\n", http.errorToString(code).c_str());

          delay(500);

          http.end();

          http.begin(client, serverName);

          http.addHeader("Content-Type", "application/x-www-form-urlencoded");

          http.setTimeout(HTTP_TIMEOUT);
        }
      }

      // =================================================
      // SERVER RESPONSE
      // =================================================

      if (code > 0) {
        String res = http.getString();

        res.trim();

        Serial.print("[SERVER] Response: ");

        Serial.println(res);

        // -------------------------------------------------
        // Parse:
        // STATUS|name|photo
        // -------------------------------------------------

        String status = res;

        String serverNameStr = "";

        String serverPhoto = "";

        int sep1 = res.indexOf('|');

        if (sep1 != -1) {
          status = res.substring(0, sep1);

          int sep2 = res.indexOf('|', sep1 + 1);

          if (sep2 != -1) {
            serverNameStr = res.substring(sep1 + 1, sep2);

            serverPhoto = res.substring(sep2 + 1);
          } else {
            serverNameStr = res.substring(sep1 + 1);
          }
        }

        status.trim();
        serverNameStr.trim();
        serverPhoto.trim();

        String displayName =
            !serverNameStr.isEmpty() ? serverNameStr : cardNameStr;

        String displayPhoto =
            !serverPhoto.isEmpty() ? serverPhoto : photoFilename;

        // =================================================
        // IMPORTANT FIX
        // =================================================
        //
        // Keep isScanning TRUE while the result is shown.
        // This prevents uiTask() from drawing SCAN/CARD
        // over NOT REGISTERED, BLOCKED, etc.
        //

        isScanning = true;

        showResultScreen = true;

        // =================================================
        // SUCCESS
        // =================================================

        if (status == "IN_OK" || status == "OUT_OK") {
          if (displayPhoto.isEmpty()) {
            ledERR();

            beepERR();

            showErrorScreen("NO PHOTO", "NOT ALLOWED");

            delay(2500);
          } else if (status == "IN_OK") {
            // Record cooldown: prevents same card retapping within 10s
            lastOfflineUid = uidStr;
            lastOfflineTime = millis();

            ledOK();

            beepOK();

            showGreeting("WELCOME", displayName, displayPhoto);

            delay(2500);
          } else {
            // Record cooldown: prevents same card retapping within 10s
            lastOfflineUid = uidStr;
            lastOfflineTime = millis();

            ledOK();

            beepBye();

            showGreeting("GOODBYE", displayName, displayPhoto);

            delay(2500);
          }
        }

        // =================================================
        // NOT REGISTERED
        // =================================================

        else if (status == "NOT_REGISTERED") {
          ledERR();

          beepERR();

          // Completely clear screen
          // before drawing the error.
          showErrorScreen("NOT", "REGISTERED");

          delay(1500);
        }

        // =================================================
        // NO PHOTO (SECURITY CHECK)
        // =================================================

        else if (status == "NO_PHOTO") {
          ledERR();

          beepERR();

          showErrorScreen("NO PHOTO", "NOT ALLOWED");

          delay(2500);
        }

        // =================================================
        // ALREADY SCANNED
        // =================================================

        else if (status == "ALREADY_SCANNED") {
          ledERR();

          beepERR();

          showErrorScreen("ALREADY", "SCANNED");

          delay(1500);
        }

        // =================================================
        // BLOCKED
        // =================================================

        else if (status == "BLOCKED") {
          ledERR();

          beepERR();

          showErrorScreen("BLOCKED", "");

          delay(1500);
        }

        // =================================================
        // UNKNOWN ERROR
        // =================================================

        else {
          Serial.print("[SERVER] Unknown status: ");

          Serial.println(status);

          ledERR();

          beepERR();

          showErrorScreen("ERROR", "");

          delay(1500);
        }
      }

      // =================================================
      // SERVER FAILURE
      // =================================================

      else {
        Serial.println("[HTTP] Server unavailable.");

        time_t now = 0;

        time(&now);

        isScanning = true;

        showResultScreen = true;

        OfflineStudentInfo offlineStudent = getOfflineStudent(uidStr);

        if (!offlineStudent.found) {
          ledERR();
          beepERR();
          showErrorScreen("NOT", "REGISTERED");
          delay(2000);
        } else if (offlineStudent.status == "BLOCKED") {
          ledERR();
          beepERR();
          showErrorScreen("BLOCKED", offlineStudent.name);
          delay(2000);
        } else if (offlineStudent.photo.isEmpty()) {
          ledERR();
          beepERR();
          showErrorScreen("NO PHOTO", "NOT ALLOWED");
          delay(2500);
        } else {
          saveOfflineScan(uidStr, (unsigned long)now);

          ledOK();
          beepOK();

          showGreeting("SAVED", offlineStudent.name, offlineStudent.photo);

          delay(2000);
        }
      }

      // =================================================
      // END HTTP
      // =================================================

      http.end();

      // =================================================
      // FINISH RESULT
      // =================================================
      //
      // ONLY HERE do we allow uiTask() to resume.
      //

      showResultScreen = false;

      isScanning = false;

      readyScreenDrawn = false;

      ledIdle();

      delay(200);
    }

    // =================================================
    // IDLE - SYNC OFFLINE
    // =================================================

    else {
      if (!isScanning && !showResultScreen) {
        syncOfflineScans();
      }
    }
  }
}

// =====================================================
// UI TASK
// =====================================================

void uiTask(void *pv) {
  unsigned long lastUpdate = 0;

  for (;;) {
    // -------------------------------------------------
    // NEVER TOUCH TFT DURING RESULT
    // -------------------------------------------------

    if (showResultScreen) {
      vTaskDelay(50 / portTICK_PERIOD_MS);

      continue;
    }

    // -------------------------------------------------
    // SCANNING
    // -------------------------------------------------

    if (isScanning) {
      showScanAnimation();

      vTaskDelay(30 / portTICK_PERIOD_MS);

      continue;
    }

    // -------------------------------------------------
    // READY SCREEN
    // -------------------------------------------------

    if (millis() - lastUpdate >= 150) {
      lastUpdate = millis();

      ledIdle();

      showReady();
    }

    vTaskDelay(30 / portTICK_PERIOD_MS);
  }
}

// =====================================================
// SETUP
// =====================================================

void setup() {
  Serial.begin(115200);

  Serial.println("\n\n=== ESP32-S3 RFID System Starting ===");

  // =================================================
  // PINS
  // =================================================

  pinMode(BUZZER, OUTPUT);

  pinMode(RGB_R_PIN, OUTPUT);

  pinMode(RGB_G_PIN, OUTPUT);

  pinMode(RGB_B_PIN, OUTPUT);

  pinMode(SD_CS, OUTPUT);
  digitalWrite(SD_CS, HIGH);

  ledIdle();

  // =================================================
  // CPU
  // =================================================

  setCpuFrequencyMhz(240);

  // =================================================
  // TFT MUTEX
  // =================================================

  tftMutex = xSemaphoreCreateMutex();

  if (tftMutex == NULL) {
    Serial.println("ERROR: Failed to create TFT mutex!");

    while (1) {
      delay(100);
    }
  }

  // =================================================
  // SPI
  // =================================================

  Serial.println("Initializing shared SPI...");

  SPI.begin(12, // SCK
            13, // MISO
            11, // MOSI
            10  // SS
  );

  SPI.setFrequency(10000000);

  // =================================================
  // TFT & BOOT SCREEN
  // =================================================

  initTFT();

  showBootScreen("Booting System...");

  delay(200);

  // =================================================
  // FLASH FILESYSTEMS (LittleFS / FFat / SPIFFS)
  // =================================================

  mountFilesystems();

  updateBootStatus("Checking Hardware...");

  // =================================================
  // PN532
  // =================================================

  Serial.println("Initializing PN532...");

  nfc.begin();

  uint32_t versiondata = nfc.getFirmwareVersion();

  if (!versiondata) {
    Serial.println("PN532 not found! Check wiring.");

    centerText("PN532", "NOT FOUND");

    while (1) {
      delay(10);
    }
  }

  Serial.printf("PN532 found. Chip: PN5%02X, FW: %d.%d\n",
                (versiondata >> 24) & 0xFF, (versiondata >> 16) & 0xFF,
                (versiondata >> 8) & 0xFF);

  nfc.SAMConfig();

  Serial.println("PN532 initialized.");

  // =================================================
  // WIFI
  // =================================================

  updateBootStatus("WiFi & Clock Sync...");

  connectWiFi();

  updateBootStatus("System Ready");

  // =================================================
  // QUEUE
  // =================================================

  rfidQueue = xQueueCreate(5, sizeof(ScanPacket));

  if (rfidQueue == NULL) {
    Serial.println("ERROR: Failed to create RFID queue!");

    centerText("QUEUE", "ERROR");

    while (1) {
      delay(100);
    }
  }

  // =================================================
  // TASKS
  // =================================================

  xTaskCreatePinnedToCore(rfidTask, "RFID", 8192, NULL, 3, NULL, 1);

  xTaskCreatePinnedToCore(serverTask, "SERVER", 16384, NULL, 3, NULL, 1);

  xTaskCreatePinnedToCore(uiTask, "UI", 8192, NULL, 2, NULL, 0);

  // =================================================
  // READY
  // =================================================

  beepReady();

  centerText("READY", "");

  delay(800);

  Serial.println("\n=== System Ready ===");

  if (psramFound()) {
    Serial.printf("PSRAM: %d bytes\n", ESP.getPsramSize());
  }

  Serial.printf("Free heap: %d bytes\n", ESP.getFreeHeap());

  Serial.printf("CPU Frequency: %d MHz\n", getCpuFrequencyMhz());

  Serial.println("Type name for registration or 'EXIT' to cancel.");
}

// =====================================================
// LOOP
// =====================================================

void loop() {
  if (Serial.available() > 0) {
    String input = Serial.readStringUntil('\n');

    input.trim();

    // =================================================
    // EXIT REGISTRATION
    // =================================================

    if (input == "EXIT") {
      isRegistrationMode = false;

      registrationName = "";

      centerText("REG", "OFF");

      delay(500);

      Serial.println("Registration Mode OFF.");
    }

    // =================================================
    // START REGISTRATION
    // =================================================

    else if (!input.isEmpty()) {
      registrationName = input;

      isRegistrationMode = true;

      centerText("REG MODE", input);

      delay(1000);

      Serial.print("Registration Mode ON. "
                   "Place card for: ");

      Serial.println(registrationName);
    }
  }

  vTaskDelay(50 / portTICK_PERIOD_MS);
}
