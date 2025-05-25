/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_WS_PORT: string;
  readonly VITE_GOOGLE_MAPS_API_KEY: string; 
 
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
