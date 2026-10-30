// Phase 42 (docs/LABEL_PRINT_PLAN.md) — Web Bluetooth can only ever reach a
// device's GATT (BLE) services, and only the ones explicitly requested via
// `optionalServices` (the API refuses access to anything not pre-declared,
// even once connected) — these are the known UART-style service UUIDs used
// by cheap BLE thermal-printer boards and generic serial modules, so the
// scan composable can ask for all of them up front. If the printer only
// speaks classic Bluetooth (SPP, no BLE at all — plausible for the XP-365B;
// RawBT's own "Bluetooth vs BLE" printer-type choice showed it was added as
// plain "Bluetooth"), none of these will ever appear and the scan correctly
// reports nothing found — that result itself is the useful signal.
export const KNOWN_SERVICES = [
    { uuid: '0000ffe0-0000-1000-8000-00805f9b34fb', name: 'HM-10 স্টাইল UART (অনেক সস্তা BLE মডিউলে)' },
    { uuid: '6e400001-b5a3-f393-e0a9-e50e24dcca9e', name: 'Nordic UART Service' },
    { uuid: '49535343-fe7d-4ae5-8fa9-9fafd205e455', name: 'ISSC/Microchip UART (অনেক ক্লোন BLE প্রিন্টারে)' },
    { uuid: '0000ff00-0000-1000-8000-00805f9b34fb', name: 'জেনেরিক ভেন্ডর সার্ভিস (FF00)' },
    { uuid: '000018f0-0000-1000-8000-00805f9b34fb', name: 'জেনেরিক প্রিন্টার সার্ভিস (18F0)' },
    { uuid: '0000180a-0000-1000-8000-00805f9b34fb', name: 'Device Information (স্ট্যান্ডার্ড)' },
];

export const KNOWN_SERVICE_UUIDS = KNOWN_SERVICES.map((s) => s.uuid);

export function serviceLabel(uuid) {
    const known = KNOWN_SERVICES.find((s) => s.uuid.toLowerCase() === String(uuid).toLowerCase());
    return known ? known.name : null;
}

/** True if a characteristic's declared properties include something this app could write label data through. */
export function isWritableCharacteristic(properties) {
    return !!(properties && (properties.write || properties.writeWithoutResponse));
}
