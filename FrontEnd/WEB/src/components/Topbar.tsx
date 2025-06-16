import React, { useState, useEffect, useRef } from "react";
import "./Topbar.css";
import avatarDefault from "../assets/avatar-default.jpg";
import { useNavigate } from "react-router-dom";

import {
  logout as apiLogout,
  fetchMe,
  fetchAvatar,
} from "../api/user";

interface NotificationItem {
  event_name: string;
  message: string;
  created_at: string; 
}

interface TopbarProps {           // interface que recebe do layout
  username?: string;
  avatarUrl?: string | null;
  bellGlow: boolean;
  notifOpen: boolean;
  onBellClick: () => void;
  notifications: NotificationItem[];       
  onClearNotifications: () => void;        
  userId: number;
}

const BRAND = "#0d47ff";


const getAuthToken = () =>           //recupera token para o websocket
  localStorage.getItem("auth_token") ?? sessionStorage.getItem("auth_token");

const Topbar: React.FC<TopbarProps> = ({    // componente que recebe do layout
  username,
  avatarUrl,
  bellGlow,
  notifOpen,
  onBellClick,
}) => {
  // estado local 
  const [localName, setLocalName] = useState<string | null>(null);
  const [localAvatar, setLocalAvatar] = useState<string | null>(null);
  const [avatarOpen, setAvatarOpen] = useState(false);

  const [notifications, setNotifications] = useState<NotificationItem[]>([]);
  const [hasUnread, setHasUnread] = useState(false);

  // referencias a  DOM
  const avatarRef = useRef<HTMLDivElement>(null);
  const notifRef = useRef<HTMLDivElement>(null);
  const bellRef = useRef<SVGSVGElement>(null);
  const wsRef = useRef<WebSocket | null>(null);

  const navigate = useNavigate();

  // -----------Obter nome e avatar do user------------------
  useEffect(() => {
    let mounted = true;

    (async () => {
      try {
        const me = await fetchMe();     // buscar dados do utilizador
        if (!mounted) return;
        setLocalName(me.name);         //guardar o nome

     
        try {     // buscar a imagem e converter em url
          const url = await fetchAvatar(me.id);
          if (mounted) setLocalAvatar(url);
        } catch (err) {
          console.error("Avatar fetch failed:", err);
        }
      } catch (err) {
        console.error("Auth fetch failed:", err);
      }
    })();

    return () => {
      mounted = false;
      if (localAvatar?.startsWith("blob:")) {      // libertar memoria
        URL.revokeObjectURL(localAvatar);
      }
    };
  }, []); 

  // ---------------Abre websocket para notificaçoes ------------------------
  useEffect(() => {
    const token = getAuthToken();        //obtem token
    if (!token) return;

    const ws = new WebSocket(`ws://localhost:55333/?token=${token}`);    // cria ligaçao ao websocket com o token
    wsRef.current = ws;

    ws.onopen = () => {
      console.log("[Topbar] WS connected");
    };

    ws.onmessage = (evt) => {
      try {          //converte a mensagem recebida em json
        const incoming = JSON.parse(evt.data) as {
          type: string;
          event_id: number;
          event_name: string;
          user_id: number;
          user_name: string;
          message: string;
          timestamp: string;
        };

        //cria uma notificaçao nova com a mensagem recebida
        const newNotif: NotificationItem = {
          event_name: incoming.event_name,
          message: incoming.message,
          created_at: incoming.timestamp,
        };

        setNotifications((prev) => [newNotif, ...prev]); //adiciona a notificaçao nova ao inicio da lista
        setHasUnread(true);      //sino abana
      } catch (err) {
        console.error("[Topbar] Failed to parse WS message:", err);
      }
    };

    ws.onerror = (err) => {
      console.error("[Topbar] WS error:", err);
    };

    ws.onclose = (e) => {
      console.log(
        `[Topbar] WS closed. code=${e.code} reason=${e.reason || "<none>"}`
      );
    };
   //quando o componente desmontar, fecha o WebSocket
    return () => {
      if (ws.readyState === WebSocket.OPEN) {
        ws.close(); // se já estiver ligado fecha imediatamente
      } else if (ws.readyState === WebSocket.CONNECTING) {
        ws.addEventListener("open", () => ws.close());     // se ainda estiver a conectar, espera terminar e depois fecha
      }
    };
  }, []); 

  // ----------------- toggle das notificaçoes ---------------------------
  useEffect(() => {
    if (notifOpen) {
       // se abre as notificaçoes considera como lidas
      setHasUnread(false);
    } else {
   
      setNotifications([]);   // se fecha limpa notificaçoes
    }
  }, [notifOpen]);

  // ------------ 4) Lida com cliques fora toggles de dropdowns e logout ---------------------------------------
  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {     // Função que verifica se o clique foi fora dos elementos
      const target = e.target as Node;

      if (
        avatarOpen &&
        avatarRef.current &&
        !avatarRef.current.contains(target)
      ) {
        setAvatarOpen(false);    // Fechar dropdown de perfil
      }

      
      if (
        notifOpen &&
        notifRef.current &&
        !notifRef.current.contains(target) &&
        bellRef.current &&
        !bellRef.current.contains(target)
      ) {
        onBellClick(); // fechar painel de notificaçoes se clicar fora
      }
    }

    document.addEventListener("mousedown", handleClickOutside);     // listener para cliques
   
    return () => {
      document.removeEventListener("mousedown", handleClickOutside);         
    };
  }, [avatarOpen, notifOpen, onBellClick]);


  const handleAvatarToggle = () => {    //toggle do dropdown do perfil
    if (notifOpen) onBellClick();
    setAvatarOpen((o) => !o);
  };
  const handleBellToggle = () => {        // toggle painel de notificaçoes
    if (avatarOpen) setAvatarOpen(false);
    onBellClick();
  };
  const handleLogout = async () => {         // faz logout
    try {
      await apiLogout();
    } catch { }
    localStorage.removeItem("auth_token");
    sessionStorage.removeItem("auth_token");
    navigate("/", { replace: true });
  };

  //---------------------- Render ---------------------------------
  const finalAvatar = localAvatar ?? avatarUrl ?? avatarDefault;
  const finalName = username ?? localName ?? "…";

  return (
    <header className="topbar">
      <div className="profile" ref={avatarRef}>
        <img
          src={finalAvatar}
          alt="Avatar"
          className="topbar-avatar"
          onClick={handleAvatarToggle}
        />
        <span className="username" onClick={handleAvatarToggle}>
          {finalName}
        </span>

        {avatarOpen && (
          <div className="avatar-dropdown">
            <button
              onClick={() => {
                setAvatarOpen(false);
                navigate("/account");
              }}
            >
              Profile
            </button>
            <button className="danger" onClick={handleLogout}>
              Logout
            </button>
          </div>
        )}

        {/* sino e notificaçoes */}
        <svg
          ref={bellRef}
          onClick={handleBellToggle}
          className={`bell-icon ${hasUnread ? "glow" : ""}`}
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 24 24"
          fill={BRAND}
        >
          <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
          <path d="M13.73 21a2 2 0 0 1-3.46 0" />
        </svg>

        {notifOpen && (
          <div className="notifications-dropdown" ref={notifRef}>
            <h4>Notifications</h4>
            <div className="notifications-list">
              {notifications.length === 0 ? (
                <p style={{ padding: "1rem", color: "#333" }}>
                  No new notifications
                </p>
              ) : (
                notifications.map((n, idx) => {
                  const key = `${n.event_name}-${n.created_at}-${idx}`;
                  return (
                    <div className="notification-item" key={key}>
                      <strong>{n.event_name}</strong>
                      <p>{n.message}</p>
                      <span className="created-at">
                        {new Date(n.created_at).toLocaleString()}
                      </span>
                    </div>
                  );
                })
              )}
            </div>
          </div>
        )}
      </div>
    </header>
  );
};

export default Topbar;
