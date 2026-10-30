import { ref } from 'vue';
import { KNOWN_SERVICE_UUIDS, serviceLabel, isWritableCharacteristic } from '@/support/bleServiceCatalog';

/**
 * Phase 42 (docs/LABEL_PRINT_PLAN.md) — a diagnostic-only Web Bluetooth
 * scan: picks a device via the browser's native chooser, connects to its
 * GATT server, and lists every service/characteristic found among the
 * known UART-style UUIDs this app knows to ask for (Web Bluetooth refuses
 * access to any service not pre-declared in `optionalServices`).
 *
 * This deliberately does NOT send anything to a printer yet — it only
 * answers "can this browser even reach this device over BLE, and if so,
 * is there a writable characteristic that looks usable." A thermal
 * printer running classic Bluetooth only (no BLE) will simply never
 * appear in the chooser at all, or connect with zero matching services —
 * both outcomes are real, useful answers, not bugs in this code.
 */
export function useBluetoothPrinterScan() {
    const supported = typeof navigator !== 'undefined' && !!navigator.bluetooth;
    const scanning = ref(false);
    const error = ref('');
    const result = ref(null); // { deviceName, services: [{ uuid, label, characteristics: [{ uuid, properties, writable }] }] }

    async function scan() {
        error.value = '';
        result.value = null;
        if (!supported) {
            error.value = 'এই ব্রাউজারে Web Bluetooth সমর্থিত নয় — Chrome for Android দিয়ে চেষ্টা করুন।';
            return;
        }

        scanning.value = true;
        try {
            const device = await navigator.bluetooth.requestDevice({
                acceptAllDevices: true,
                optionalServices: KNOWN_SERVICE_UUIDS,
            });

            const server = await device.gatt.connect();
            const services = [];
            for (const uuid of KNOWN_SERVICE_UUIDS) {
                try {
                    const service = await server.getPrimaryService(uuid);
                    const chars = await service.getCharacteristics();
                    services.push({
                        uuid,
                        label: serviceLabel(uuid),
                        characteristics: chars.map((c) => ({
                            uuid: c.uuid,
                            properties: c.properties,
                            writable: isWritableCharacteristic(c.properties),
                        })),
                    });
                } catch (e) {
                    // this specific service isn't present on this device — expected for most of the list, not an error
                }
            }
            server.disconnect();

            result.value = { deviceName: device.name || '(নাম পাওয়া যায়নি)', services };
        } catch (e) {
            if (e?.name === 'NotFoundError') {
                error.value = 'কোনো ডিভাইস বাছা হয়নি, অথবা তালিকায় প্রিন্টারটি দেখানো যায়নি।';
            } else {
                error.value = 'সংযোগ ব্যর্থ: ' + (e?.message || String(e));
            }
        } finally {
            scanning.value = false;
        }
    }

    return { supported, scanning, error, result, scan };
}
