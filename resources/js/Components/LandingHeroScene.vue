<script setup>
/**
 * Real WebGL 3D scene for the landing-page hero — a rotating phone showing
 * the actual Zaylotix POS screen, with a few product blocks orbiting it.
 * Kept to ONE Three.js canvas on the whole page (the rest of the page's
 * motion is GSAP/CSS) on purpose: five always-running WebGL canvases down
 * one long page is a real battery/perf cost on a mid-range Android phone
 * (exactly the device this product is actually used on), not a cost worth
 * paying five times over for a marketing page.
 *
 * Mounts lazily (IntersectionObserver — only starts once the hero is
 * actually visible), pauses its render loop when scrolled out of view, and
 * fully disposes the renderer/geometries/materials/textures on unmount
 * (Inertia navigates away without a full page reload, so a leaked
 * WebGLRenderer would otherwise outlive this component).
 *
 * Falls back to nothing rendered (the plain phone-mock screenshot behind
 * it, see Landing/Index.vue, stays visible through the gap) when WebGL is
 * unavailable or the visitor has prefers-reduced-motion on.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import * as THREE from 'three';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

const props = defineProps({ screenImage: { type: String, required: true } });

const canvasHost = ref(null);
const ready = ref(false);

let renderer = null;
let scene = null;
let camera = null;
let phoneGroup = null;
let floaters = [];
let rafId = null;
let resizeObserver = null;
let intersectionObserver = null;
let scrollTween = null;
let isVisible = false;
let disposed = false;

const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
// a cheap, honest-effort heuristic for "this is probably a modest phone" —
// never perfect, just enough to keep the floating product count and pixel
// ratio sane on the exact low/mid-range Android hardware this app targets
const isLowPower = (navigator.hardwareConcurrency || 4) <= 4 && Math.min(window.innerWidth, window.innerHeight) < 500;

function supportsWebGL() {
    try {
        const c = document.createElement('canvas');
        return !!(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
    } catch {
        return false;
    }
}

function buildScene(host) {
    const width = host.clientWidth;
    const height = host.clientHeight;

    scene = new THREE.Scene();

    camera = new THREE.PerspectiveCamera(32, width / height, 0.1, 100);
    camera.position.set(0, 0.3, 9);

    renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'low-power' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, isLowPower ? 1.25 : 2));
    renderer.setSize(width, height);
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    host.appendChild(renderer.domElement);

    // lighting — a key light (cast shadow), a cool rim light from behind for
    // edge separation against the dark navy section, and a soft ambient
    // fill so the phone's dark body never reads as a flat black silhouette
    const key = new THREE.DirectionalLight(0xffffff, 2.4);
    key.position.set(3.5, 4, 5);
    key.castShadow = true;
    key.shadow.mapSize.set(512, 512);
    scene.add(key);

    const rim = new THREE.DirectionalLight(0x7c3aed, 1.1);
    rim.position.set(-4, 1.5, -3);
    scene.add(rim);

    const ambient = new THREE.AmbientLight(0x8fa5ff, 0.55);
    scene.add(ambient);

    // the phone — a dark rounded body (approximated with a beveled box via
    // segments) + the real POS screenshot as a texture on its front face
    phoneGroup = new THREE.Group();

    const bodyGeo = new THREE.BoxGeometry(2.05, 4.2, 0.22, 4, 8, 1);
    const bodyMat = new THREE.MeshStandardMaterial({ color: 0x0b0b12, roughness: 0.35, metalness: 0.4 });
    const body = new THREE.Mesh(bodyGeo, bodyMat);
    body.castShadow = true;
    body.receiveShadow = true;
    phoneGroup.add(body);

    const screenTexture = new THREE.TextureLoader().load(props.screenImage);
    screenTexture.colorSpace = THREE.SRGBColorSpace;
    const screenGeo = new THREE.PlaneGeometry(1.86, 3.95);
    const screenMat = new THREE.MeshStandardMaterial({ map: screenTexture, roughness: 0.5, emissive: 0xffffff, emissiveMap: screenTexture, emissiveIntensity: 0.35 });
    const screen = new THREE.Mesh(screenGeo, screenMat);
    screen.position.z = 0.115;
    phoneGroup.add(screen);

    phoneGroup.rotation.y = -0.45;
    phoneGroup.rotation.x = 0.06;
    scene.add(phoneGroup);

    // a few floating "product" blocks around the phone — the orange/green/
    // blue accent palette, not another purple gradient
    const floaterCount = isLowPower ? 3 : 5;
    const colors = [0x20a36a, 0xff8a36, 0x4285e8, 0x7138e8, 0x20a36a];
    floaters = [];
    for (let i = 0; i < floaterCount; i++) {
        const size = 0.3 + (i % 2) * 0.14;
        const geo = i % 2 === 0 ? new THREE.BoxGeometry(size, size, size) : new THREE.IcosahedronGeometry(size * 0.62, 0);
        const mat = new THREE.MeshStandardMaterial({ color: colors[i % colors.length], roughness: 0.4, metalness: 0.15 });
        const mesh = new THREE.Mesh(geo, mat);
        mesh.castShadow = true;
        const angle = (i / floaterCount) * Math.PI * 2;
        const radius = 2.9 + (i % 2) * 0.5;
        mesh.userData.baseX = Math.cos(angle) * radius;
        mesh.userData.baseZ = Math.sin(angle) * radius * 0.6 - 1;
        mesh.userData.baseY = -0.6 + (i % 3) * 0.7;
        mesh.userData.bobSpeed = 0.6 + i * 0.13;
        mesh.userData.bobOffset = i * 1.3;
        mesh.position.set(mesh.userData.baseX, mesh.userData.baseY, mesh.userData.baseZ);
        scene.add(mesh);
        floaters.push(mesh);
    }

    // an invisible shadow-catcher behind the phone, so the key light's
    // shadow has somewhere real to land instead of never being seen
    const catcherMat = new THREE.ShadowMaterial({ opacity: 0.25 });
    const catcher = new THREE.Mesh(new THREE.PlaneGeometry(14, 14), catcherMat);
    catcher.position.z = -2.4;
    catcher.receiveShadow = true;
    scene.add(catcher);
}

function tick(elapsed) {
    if (disposed) return;
    rafId = requestAnimationFrame(tick);
    if (!isVisible) return;

    const t = elapsed * 0.001;
    for (const f of floaters) {
        f.position.y = f.userData.baseY + Math.sin(t * f.userData.bobSpeed + f.userData.bobOffset) * 0.22;
        f.rotation.x += 0.004;
        f.rotation.y += 0.006;
    }
    if (!scrollTween) {
        phoneGroup.rotation.y += Math.sin(t * 0.2) * 0.0004;
    }
    renderer.render(scene, camera);
}

function onResize() {
    if (!renderer || !camera || !canvasHost.value) return;
    const w = canvasHost.value.clientWidth;
    const h = canvasHost.value.clientHeight;
    if (!w || !h) return;
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
    renderer.setSize(w, h);
}

onMounted(() => {
    if (reducedMotion || !supportsWebGL() || !canvasHost.value) return;

    intersectionObserver = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            isVisible = entry.isIntersecting;
            if (isVisible && !renderer) {
                buildScene(canvasHost.value);
                ready.value = true;

                // scroll-scrubbed camera/phone move — the "cinematic camera
                // movement" ask, kept inside the hero's own height so it
                // never fights the page's normal scroll
                scrollTween = gsap.timeline({
                    scrollTrigger: { trigger: canvasHost.value.closest('.lp-hero'), start: 'top top', end: 'bottom top', scrub: true },
                })
                    .to(phoneGroup.rotation, { y: 0.55, x: -0.1, ease: 'none' }, 0)
                    .to(camera.position, { y: -0.4, z: 7.5, ease: 'none' }, 0);

                rafId = requestAnimationFrame(tick);
            }
        }
    }, { threshold: 0.05 });
    intersectionObserver.observe(canvasHost.value);

    resizeObserver = new ResizeObserver(onResize);
    resizeObserver.observe(canvasHost.value);
});

onBeforeUnmount(() => {
    disposed = true;
    if (rafId) cancelAnimationFrame(rafId);
    intersectionObserver?.disconnect();
    resizeObserver?.disconnect();
    scrollTween?.scrollTrigger?.kill();
    scrollTween?.kill();

    if (scene) {
        scene.traverse((obj) => {
            obj.geometry?.dispose?.();
            if (obj.material) {
                const mats = Array.isArray(obj.material) ? obj.material : [obj.material];
                mats.forEach((m) => { m.map?.dispose?.(); m.emissiveMap?.dispose?.(); m.dispose?.(); });
            }
        });
    }
    renderer?.dispose();
    renderer?.domElement?.remove();
});
</script>

<template>
    <div ref="canvasHost" class="lp-hero-canvas" :class="{ 'is-ready': ready }"></div>
</template>
