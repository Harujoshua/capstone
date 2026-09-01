#include <WiFi.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <FFat.h>
#include "time.h"

// ================= CONFIGURATION =================
#define SS_PIN 10
#define RST_PIN 9
#define BUZZER 6
#define RGB_R_PIN 16
#define RGB_G_PIN 17
#define RGB_B_PIN 18
#define SDA_PIN 4
#define SCL_PIN 5

#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
#define OLED_ADDRESS 0x3C
#define NAME_BLOCK 4
#define SCAN_COOLDOWN 500          // Reduced from 1000ms
#define WIFI_TIMEOUT 5000          // Reduced from 10000ms
#define HTTP_TIMEOUT 3000          // Faster timeout for local HTTP
#define MAX_RETRIES 3

// ================= GLOBALS =================
MFRC522 rfid(SS_PIN, RST_PIN);
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, -1);
MFRC522::MIFARE_Key key;

// WiFi
const char* ssid = "LW-INTERNET SERVICES";
const char* password = "eeeeeeee";
const char* serverName = "http://192.168.100.65/neust_gatepass/scan.php";

// NTP
const char* ntpServer = "pool.ntp.org";
const long gmtOffset_sec = 28800;
const int daylightOffset_sec = 0;

// State
bool showResultScreen = false;
bool isScanning = false;
bool isRegistrationMode = false;
bool oledInitialized = false;
unsigned long resultStartTime = 0;
unsigned long lastScanTime = 0;
String registrationName = "";
QueueHandle_t rfidQueue;

// HTTP Client
WiFiClient client;
HTTPClient http;

// ================= HELPER FUNCTIONS =================
inline void setColor(int r, int g, int b) {
    analogWrite(RGB_R_PIN, r);
    analogWrite(RGB_G_PIN, g);
    analogWrite(RGB_B_PIN, b);
}

inline void ledIdle() { setColor(0, 0, 255); }
inline void ledScan() { setColor(255, 60, 0); }
inline void ledOK() { setColor(0, 255, 0); }
inline void ledERR() { setColor(255, 0, 0); }

inline void beep(int freq, int duration) {
    tone(BUZZER, freq, duration);
}

void beepOK() {
    beep(2000, 80);
    delay(100);
    beep(2500, 80);
    delay(100);
    beep(3000, 100);
}

void beepERR() {
    for(int i = 0; i < 2; i++) {
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

// ================= DISPLAY FUNCTIONS =================
bool initOLED() {
    Serial.println("Initializing OLED...");
    Wire.begin(SDA_PIN, SCL_PIN);
    Wire.setClock(400000);
    
    delay(50);
    
    if(display.begin(SSD1306_SWITCHCAPVCC, 0x3C)) {
        Serial.println("OLED found at 0x3C");
        oledInitialized = true;
        display.clearDisplay();
        display.display();
        return true;
    }
    
    if(display.begin(SSD1306_SWITCHCAPVCC, 0x3D)) {
        Serial.println("OLED found at 0x3D");
        oledInitialized = true;
        display.clearDisplay();
        display.display();
        return true;
    }
    
    Serial.println("OLED not found!");
    oledInitialized = false;
    return false;
}

void centerText(const String& line1, const String& line2 = "") {
    if(!oledInitialized) {
        Serial.println("OLED: " + line1 + " - " + line2);
        return;
    }
    
    display.clearDisplay();
    display.setTextSize(2);
    display.setTextColor(WHITE);
    
    if(line2.isEmpty()) {
        int16_t x, y;
        uint16_t w, h;
        display.getTextBounds(line1, 0, 0, &x, &y, &w, &h);
        display.setCursor((SCREEN_WIDTH - w) / 2, (SCREEN_HEIGHT - h) / 2);
        display.println(line1);
    } else {
        int16_t x, y;
        uint16_t w, h;
        display.getTextBounds(line1, 0, 0, &x, &y, &w, &h);
        display.setCursor((SCREEN_WIDTH - w) / 2, SCREEN_HEIGHT / 2 - 15);
        display.println(line1);
        
        display.setTextSize(1);
        display.getTextBounds(line2, 0, 0, &x, &y, &w, &h);
        display.setCursor((SCREEN_WIDTH - w) / 2, SCREEN_HEIGHT / 2 + 10);
        display.println(line2);
    }
    display.display();
}

void showScanAnimation() {
    if(!oledInitialized || !isScanning) return;
    
    static int step = 0;
    static unsigned long lastUpdate = 0;
    
    if(millis() - lastUpdate >= 60) {  // Faster animation
        step = (step + 1) % 8;
        lastUpdate = millis();
    }
    
    display.clearDisplay();
    display.setTextSize(2);
    display.setTextColor(WHITE);
    display.setCursor((SCREEN_WIDTH - 80) / 2, 10);
    display.println("SCANNING");
    
    int cx = SCREEN_WIDTH / 2;
    int cy = 42;
    for(int i = 0; i < 8; i++) {
        float angle = i * PI / 4.0;
        int x = cx + 12 * cos(angle);
        int y = cy + 12 * sin(angle);
        display.fillCircle(x, y, (i == step) ? 3 : 1, WHITE);
    }
    display.display();
}

void showReady() {
    if(!oledInitialized) return;
    
    static unsigned long lastBlink = 0;
    static bool blinkState = true;
    
    if(millis() - lastBlink >= 400) {  // Faster blink
        blinkState = !blinkState;
        lastBlink = millis();
    }
    
    display.clearDisplay();
    
    if(isRegistrationMode) {
        display.setTextSize(2);
        display.setTextColor(WHITE);
        display.setCursor((SCREEN_WIDTH - 100) / 2, 10);
        display.println("REG MODE");
        display.setTextSize(1);
        display.setCursor((SCREEN_WIDTH - registrationName.length() * 6) / 2, 40);
        display.println(registrationName);
    } else if(blinkState) {
        display.setTextSize(3);
        display.setTextColor(WHITE);
        display.setCursor((SCREEN_WIDTH - 60) / 2, SCREEN_HEIGHT / 2 - 20);
        display.println("SCAN");
        display.setCursor((SCREEN_WIDTH - 60) / 2, SCREEN_HEIGHT / 2 + 5);
        display.println("CARD");
    }
    display.display();
}

void showGreeting(const String& status, const String& name) {
    if(!oledInitialized) {
        Serial.println("Greeting: " + status + " - " + name);
        return;
    }
    
    display.clearDisplay();
    display.setTextSize(2);
    display.setTextColor(WHITE);
    display.setCursor((SCREEN_WIDTH - status.length() * 12) / 2, SCREEN_HEIGHT / 2 - 15);
    display.println(status);
    display.setTextSize(1);
    display.setCursor((SCREEN_WIDTH - name.length() * 6) / 2, SCREEN_HEIGHT / 2 + 10);
    display.println(name);
    display.display();
}

// ================= RFID FUNCTIONS =================
String readCardName() {
    if(rfid.PCD_Authenticate(MFRC522::PICC_CMD_MF_AUTH_KEY_A, NAME_BLOCK, &key, &(rfid.uid)) != MFRC522::STATUS_OK) {
        return "";
    }
    
    byte buffer[18];
    byte size = sizeof(buffer);
    if(rfid.MIFARE_Read(NAME_BLOCK, buffer, &size) != MFRC522::STATUS_OK) {
        return "";
    }
    
    String name = "";
    for(uint8_t i = 0; i < 16 && buffer[i] != 0; i++) {
        if(isPrintable(buffer[i])) name += (char)buffer[i];
    }
    name.trim();
    return name;
}

bool writeCardName(const String& name) {
    if(rfid.PCD_Authenticate(MFRC522::PICC_CMD_MF_AUTH_KEY_A, NAME_BLOCK, &key, &(rfid.uid)) != MFRC522::STATUS_OK) {
        return false;
    }
    
    byte data[16] = {0};
    uint8_t len = min(name.length(), (size_t)16);
    for(uint8_t i = 0; i < len; i++) data[i] = name[i];
    
    return rfid.MIFARE_Write(NAME_BLOCK, data, 16) == MFRC522::STATUS_OK;
}

String getUID() {
    String uid = "";
    for(byte i = 0; i < rfid.uid.size; i++) {
        if(rfid.uid.uidByte[i] < 0x10) uid += "0";
        uid += String(rfid.uid.uidByte[i], HEX);
    }
    uid.toUpperCase();
    return uid;
}

// ================= WIFI =================
bool isWiFiConnected() {
    return WiFi.status() == WL_CONNECTED;
}

void connectWiFi() {
    centerText("CONNECTING", "WiFi");
    WiFi.begin(ssid, password);
    
    unsigned long start = millis();
    while(WiFi.status() != WL_CONNECTED && millis() - start < WIFI_TIMEOUT) {
        delay(250);
        centerText("CONNECTING", "WiFi" + String((millis() / 500) % 4 == 0 ? "" : "..."));
    }
    
    if(WiFi.status() == WL_CONNECTED) {
        beepReady();
        centerText("WiFi", "CONNECTED");
        delay(1000);
        configTime(gmtOffset_sec, daylightOffset_sec, ntpServer);
    } else {
        beepERR();
        centerText("WiFi", "FAILED");
        delay(1000);
    }
}

// ================= RFID TASK =================
void rfidTask(void* pv) {
    for(;;) {
        if(millis() - lastScanTime < SCAN_COOLDOWN) {
            vTaskDelay(10 / portTICK_PERIOD_MS);
            continue;
        }
        
        if(!rfid.PICC_IsNewCardPresent() || !rfid.PICC_ReadCardSerial()) {
            vTaskDelay(10 / portTICK_PERIOD_MS);
            continue;
        }
        
        lastScanTime = millis();
        
        if(isRegistrationMode) {
            String uid = getUID();
            Serial.print("Card UID: ");
            Serial.println(uid);
            
            if(writeCardName(registrationName)) {
                ledOK();
                beepOK();
                showGreeting("REGISTERED", registrationName);
                delay(1500);
            } else {
                ledERR();
                beepERR();
                showGreeting("FAILED", "Try Again");
                delay(1500);
            }
            
            rfid.PICC_HaltA();
            rfid.PCD_StopCrypto1();
            isRegistrationMode = false;
            continue;
        }
        
        String uid = getUID();
        xQueueSend(rfidQueue, &uid, portMAX_DELAY);
        vTaskDelay(100 / portTICK_PERIOD_MS);
    }
}

// ================= SERVER TASK =================
void serverTask(void* pv) {
    String uid;
    char uidBuffer[32];
    
    for(;;) {
        if(xQueueReceive(rfidQueue, &uid, portMAX_DELAY)) {
            isScanning = true;
            ledScan();
            
            if(!isWiFiConnected()) {
                isScanning = false;
                showResultScreen = true;
                ledERR();
                showGreeting("NO WIFI", "Check Connection");
                beepERR();
                delay(1500);
                showResultScreen = false;
                rfid.PICC_HaltA();
                rfid.PCD_StopCrypto1();
                ledIdle();
                delay(200);
                continue;
            }
            
            // Prepare UID for POST
            uid.toCharArray(uidBuffer, sizeof(uidBuffer));
            
            // Clean HTTP connection for every scan
            http.begin(client, serverName);
            http.addHeader("Content-Type", "application/x-www-form-urlencoded");
            http.setTimeout(HTTP_TIMEOUT);
            
            // Send request with retry
            int code = -1;
            for(int retry = 0; retry < MAX_RETRIES && code <= 0; retry++) {
                code = http.POST("uid=" + String(uidBuffer));
                if(code <= 0 && retry < MAX_RETRIES - 1) {
                    Serial.printf("HTTP Error: %s\n", http.errorToString(code).c_str());
                    delay(500);  // Larger delay before retry
                    http.end();
                    http.begin(client, serverName);
                    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
                    http.setTimeout(HTTP_TIMEOUT);
                }
            }
            
            if(code > 0) {
                String res = http.getString();
                String status = res;
                String name = "";
                int sep = res.indexOf('|');
                if(sep != -1) {
                    status = res.substring(0, sep);
                    name = res.substring(sep + 1);
                }
                
                isScanning = false;
                showResultScreen = true;
                
                if(status == "IN_OK" || status == "OUT_OK") {
                    if(status == "IN_OK") {
                        ledOK();
                        showGreeting("WELCOME", name);
                        beepOK();
                    } else {
                        ledOK();
                        showGreeting("GOODBYE", name);
                        beepBye();
                    }
                    delay(1500);  // Reduced from 2500ms
                    
                    // Only read/write name if we have a name from server
                    if(!name.isEmpty()) {
                        String cardName = readCardName();
                        if(name != cardName) {
                            writeCardName(name);
                        }
                    }
                } else if(status == "NOT_REGISTERED") {
                    ledERR();
                    showGreeting("NOT", "REGISTERED");
                    beepERR();
                    delay(1200);
                } else if(status == "ALREADY_SCANNED") {
                    ledERR();
                    showGreeting("ALREADY", "SCANNED");
                    beepERR();
                    delay(1200);
                } else if(status == "BLOCKED") {
                    ledERR();
                    showGreeting("BLOCKED", "");
                    beepERR();
                    delay(1200);
                } else {
                    ledERR();
                    showGreeting("ERROR", "");
                    beepERR();
                    delay(1200);
                }
            } else {
                isScanning = false;
                showResultScreen = true;
                ledERR();
                showGreeting("SERVER", "ERROR");
                beepERR();
                delay(1500);
                
            }
            
            http.end();  // Always close connection
            
            isScanning = false;
            showResultScreen = false;
            rfid.PICC_HaltA();
            rfid.PCD_StopCrypto1();
            ledIdle();
            delay(200);
        }
    }
}

// ================= UI TASK =================
void uiTask(void* pv) {
    unsigned long lastUpdate = 0;
    
    for(;;) {
        if(isScanning) {
            showScanAnimation();
            vTaskDelay(30 / portTICK_PERIOD_MS);
            continue;
        }
        
        if(showResultScreen) {
            vTaskDelay(50 / portTICK_PERIOD_MS);
            continue;
        }
        
        if(millis() - lastUpdate >= 150) {  // Faster UI updates
            lastUpdate = millis();
            ledIdle();
            showReady();
        }
        
        vTaskDelay(30 / portTICK_PERIOD_MS);
    }
}

// ================= SETUP =================
void setup() {
    Serial.begin(115200);
    Serial.println("\n\n=== ESP32-S3 RFID System Starting ===");
    
    // Initialize pins
    pinMode(BUZZER, OUTPUT);
    pinMode(RGB_R_PIN, OUTPUT);
    pinMode(RGB_G_PIN, OUTPUT);
    pinMode(RGB_B_PIN, OUTPUT);
    ledIdle();
    
    // Increase CPU speed for faster processing
    setCpuFrequencyMhz(240);
    
    // Initialize OLED
    initOLED();
    if(oledInitialized) {
        centerText("BOOTING", "System");
        delay(300);
    }
    
    // Initialize FFat
    if(!FFat.begin(true)) {
        Serial.println("FFat Mount Failed");
    }
    
    // Initialize RFID - faster SPI
    Serial.println("Initializing RFID...");
    SPI.begin(12, 13, 11, 10);
    SPI.setFrequency(10000000);  // 10MHz SPI for faster RFID
    rfid.PCD_Init();
    rfid.PCD_SetAntennaGain(MFRC522::RxGain_max);  // Max gain for better detection
    for(byte i = 0; i < 6; i++) key.keyByte[i] = 0xFF;
    Serial.println("RFID initialized");
    
    // Connect WiFi
    connectWiFi();
    
    // Create queue
    rfidQueue = xQueueCreate(5, sizeof(String));
    
    // Create tasks with higher priority for server
    xTaskCreatePinnedToCore(rfidTask, "RFID", 8192, NULL, 3, NULL, 1);
    xTaskCreatePinnedToCore(serverTask, "SERVER", 16384, NULL, 3, NULL, 1);
    xTaskCreatePinnedToCore(uiTask, "UI", 8192, NULL, 2, NULL, 0);
    
    beepReady();
    if(oledInitialized) {
        centerText("READY", "");
        delay(800);
    }
    
    Serial.println("\n=== System Ready ===");
    if(psramFound()) {
        Serial.printf("PSRAM: %d bytes\n", ESP.getPsramSize());
    }
    Serial.printf("Free heap: %d bytes\n", ESP.getFreeHeap());
    Serial.printf("CPU Frequency: %d MHz\n", getCpuFrequencyMhz());
    
    Serial.println("Type name for registration or 'EXIT' to cancel.");
}

// ================= LOOP =================
void loop() {
    if(Serial.available() > 0) {
        String input = Serial.readStringUntil('\n');
        input.trim();
        
        if(input == "EXIT") {
            isRegistrationMode = false;
            if(oledInitialized) centerText("REG", "OFF");
            delay(500);
            Serial.println("Registration Mode OFF.");
        } else if(!input.isEmpty()) {
            registrationName = input;
            isRegistrationMode = true;
            if(oledInitialized) centerText("REG MODE", input);
            delay(1000);
            Serial.print("Registration Mode ON. Place card for: ");
            Serial.println(registrationName);
        }
    }
    vTaskDelay(50 / portTICK_PERIOD_MS);
}