import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/js/app.js", "resources/css/app.css"],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],

    // ★重要：Laravel( http://localhost:8000 ) と揃える
    // - 127.0.0.1 / localhost を混ぜると別オリジンになって CORS/HMR が壊れる
    server: {
        host: "localhost",
        port: 5173,
        strictPort: true,
        hmr: {
            host: "localhost",
            port: 5173,
        },
    },
});
