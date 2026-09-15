import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
export default defineConfig(({mode})=>{
 const appPath=(loadEnv(mode,'.','VITE_').VITE_APP_BASE_PATH||'').replace(/\/$/,'');
 return {base:`${appPath}/build/`,plugins:[react()],build:{outDir:'../backend/public/build',emptyOutDir:true},server:{proxy:{'/api':'http://localhost:8000'}}};
});
