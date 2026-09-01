#include <SPI.h>
#include <MFRC522.h>

#define SS_PIN 10
#define RST_PIN 9

MFRC522 mfrc522(SS_PIN, RST_PIN);

String studentName = "";

void setup() {
  Serial.begin(115200);
  SPI.begin();
  mfrc522.PCD_Init();

  Serial.println("=== RFID Student Registration ===");
  Serial.println("Enter student name:");
}

void loop() {

  if (Serial.available()) {

    studentName = Serial.readStringUntil('\n');
    studentName.trim();

    if (studentName.length() == 0) {
      Serial.println("Invalid name.");
      return;
    }

    Serial.print("Registering: ");
    Serial.println(studentName);
    Serial.println("Place card on reader...");

    while (!mfrc522.PICC_IsNewCardPresent() ||
           !mfrc522.PICC_ReadCardSerial()) {
      delay(100);
    }

    MFRC522::MIFARE_Key key;

    for (byte i = 0; i < 6; i++) {
      key.keyByte[i] = 0xFF;
    }

    byte block = 4;
    byte data[16];

    memset(data, 0, sizeof(data));

    for (byte i = 0; i < studentName.length() && i < 16; i++) {
      data[i] = studentName[i];
    }

    MFRC522::StatusCode status;

    status = mfrc522.PCD_Authenticate(
      MFRC522::PICC_CMD_MF_AUTH_KEY_A,
      block,
      &key,
      &(mfrc522.uid)
    );

    if (status != MFRC522::STATUS_OK) {
      Serial.print("Authentication failed: ");
      Serial.println(mfrc522.GetStatusCodeName(status));
      return;
    }

    status = mfrc522.MIFARE_Write(block, data, 16);

    if (status != MFRC522::STATUS_OK) {
      Serial.print("Write failed: ");
      Serial.println(mfrc522.GetStatusCodeName(status));
    } else {
      Serial.println("✅ Card Registered Successfully");
    }

    mfrc522.PICC_HaltA();
    mfrc522.PCD_StopCrypto1();

    Serial.println("\nEnter next student name:");
  }
}#include <SPI.h>
#include <MFRC522.h>

#define SS_PIN 10
#define RST_PIN 9

MFRC522 mfrc522(SS_PIN, RST_PIN);

String studentName = "";

void setup() {
  Serial.begin(115200);
  SPI.begin();
  mfrc522.PCD_Init();

  Serial.println("=== RFID Student Registration ===");
  Serial.println("Enter student name:");
}

void loop() {

  if (Serial.available()) {

    studentName = Serial.readStringUntil('\n');
    studentName.trim();

    if (studentName.length() == 0) {
      Serial.println("Invalid name.");
      return;
    }

    Serial.print("Registering: ");
    Serial.println(studentName);
    Serial.println("Place card on reader...");

    while (!mfrc522.PICC_IsNewCardPresent() ||
           !mfrc522.PICC_ReadCardSerial()) {
      delay(100);
    }

    MFRC522::MIFARE_Key key;

    for (byte i = 0; i < 6; i++) {
      key.keyByte[i] = 0xFF;
    }

    byte block = 4;
    byte data[16];

    memset(data, 0, sizeof(data));

    for (byte i = 0; i < studentName.length() && i < 16; i++) {
      data[i] = studentName[i];
    }

    MFRC522::StatusCode status;

    status = mfrc522.PCD_Authenticate(
      MFRC522::PICC_CMD_MF_AUTH_KEY_A,
      block,
      &key,
      &(mfrc522.uid)
    );

    if (status != MFRC522::STATUS_OK) {
      Serial.print("Authentication failed: ");
      Serial.println(mfrc522.GetStatusCodeName(status));
      return;
    }

    status = mfrc522.MIFARE_Write(block, data, 16);

    if (status != MFRC522::STATUS_OK) {
      Serial.print("Write failed: ");
      Serial.println(mfrc522.GetStatusCodeName(status));
    } else {
      Serial.println("✅ Card Registered Successfully");
    }

    mfrc522.PICC_HaltA();
    mfrc522.PCD_StopCrypto1();

    Serial.println("\nEnter next student name:");
  }
}