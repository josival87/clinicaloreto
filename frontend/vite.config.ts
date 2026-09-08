import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
export default defineConfig({base:'/build/',plugins:[react()],build:{outDir:'../backend/public/build',emptyOutDir:true},server:{proxy:{'/api':'http://localhost:8000'}}});
