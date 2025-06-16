
import React, { useEffect, useState, useRef } from "react";
import { useParams, useNavigate } from "react-router-dom";
import "./ChatRoom.css";

 // interface da mensagem do chat
interface ChatMessage {
  id?: number;
  event_id: number;
  user_id: number | string;
  user_name: string;
  message: string;
  timestamp: string;
}

// interface do participante
interface Participant {
  id: number;
  name: string;
  rating: number | null;
}

// interface do evento
interface EventDTO {
  id: number;
  name: string;
  sport: string;
  date: string;
  place: string;
  status: string;
  max_participants: number;
  latitude: string;
  longitude: string;
  creator: { id: number; name: string };
  weather: {
    app_max_temp: number;
    app_min_temp: number;
    temp: number;
    high_temp: number;
    low_temp: number;
    description: string;
  };
  participants: Participant[];
}

const getAuthToken = (): string | null =>     // vai buscar o token
  localStorage.getItem("auth_token") || sessionStorage.getItem("auth_token");

const getUserId = (): number | null => {   // extrai o id do token
  const token = getAuthToken();
  if (!token) return null;
  try {
    const payload = JSON.parse(atob(token.split(".")[1]));
    return Number(payload.sub);
  } catch {
    return null;
  }
};

const ChatRoom: React.FC = () => {
  // obtem o id do evento
  const { id } = useParams();
  const eventId = Number(id);
  const nav = useNavigate();

  // ------------estados-------
  const [eventName, setEventName] = useState<string>("");
  const [participants, setParticipants] = useState<Participant[]>([]);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [input, setInput] = useState("");
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

// ----- referencias do websocket----------
  const wsRef = useRef<WebSocket | null>(null);
  const containerRef = useRef<HTMLDivElement | null>(null);
  // token e id do user
  const token = getAuthToken();
  const myId = getUserId();

  // ------se nao for autenticado vai para o login------- 
  useEffect(() => {
    if (!token) {
      setError("Acesso não autorizado.");
      nav("/", { replace: true });
    }
  }, [token, nav]);

  // ------- vai buscar os detalhes do evento e participantes-----------------
  useEffect(() => {
    if (!token) return;

    fetch("http://localhost:8081/api/events", {    // chama api
      headers: { Authorization: `Bearer ${token}` },
    })
      .then(async (res) => {
        if (res.status === 401) throw new Error("Não autorizado.");
        return res.json();     
      })
      .then((resData) => {
        const events: EventDTO[] = resData.data;
        const ev = events.find((e) => e.id === eventId);
        if (ev) {
          setEventName(ev.name);
          setParticipants(ev.participants);
        } else {
          setEventName(`Evento ${eventId}`);
          setParticipants([]);
        }
      })
      .catch((err) => {
        console.error("Fetch events error:", err);
        setEventName(`Evento ${eventId}`);
        setParticipants([]);
      });
  }, [eventId, token]);

  // ── vai buscar as mensagens anteriores ao carregar o chat─────────────────────────────────────────────────────
  useEffect(() => {
    if (!token) return;

    setLoading(true);
    fetch(`http://localhost:8082/api/fetchMessages/${eventId}`, {  // chama o api
      headers: { Authorization: `Bearer ${token}` },
    })
      .then(async (res) => {
        if (res.status === 401) throw new Error("Não autorizado.");
        return res.json();
      })
      .then((data) => {       // atualiza as mensagens 
        setMessages(data.messages || []);
      })
      .catch((err) => {
        console.error("Fetch messages error:", err);
        setError("Não foi possível carregar as mensagens.");
      })
      .finally(() => setLoading(false));
  }, [eventId, token]);

  // ------ faz scroll para baixo sempre que ha mensagem --------
  useEffect(() => {
    if (containerRef.current) {
      containerRef.current.scrollTop = containerRef.current.scrollHeight;
    }
  }, [messages]);

  // ── WebSocket em tempo real ───────────────────────────────────────────
  useEffect(() => {
    if (!token) return;

    const port = import.meta.env.VITE_WS_PORT || "55333";   //porta
    const ws = new WebSocket(`ws://localhost:${port}/?token=${token}`);  // cria a conexao com o token
    wsRef.current = ws;

    ws.onmessage = ({ data }) => {  // quando recebe  uma mensagem
      let msg: ChatMessage;
      try {
        msg = JSON.parse(data);  // faz parse do json recebido
      } catch {
        return;
      }
      if (msg.event_id === eventId) {   // verifica se a mensagem e para o evento atual
        setMessages((prev) => [...prev, msg]);
      }
    };

    ws.onerror = (e) => console.error("WebSocket error:", e);
    ws.onclose = () => console.log("WebSocket closed");

    return () => ws.close();
  }, [eventId, token]);

  // ----------------enviar uma mensagem---------------------
  const sendMessage = async () => {
    if (!input.trim() || !token) return;    // nao envia se estiver vazia ou nao tiver token

    try {
      const res = await fetch(
        `http://localhost:8082/api/sendMessage/${eventId}`,   // chama a api
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({ message: input }),   // mensagem em json
        }
      );

      if (res.status === 401) {
        setError("Sessão expirada.");
        nav("/", { replace: true });
        return;
      }

      setInput("");  // limpa o campo depois de enviar
    } catch (err) {
      console.error("Send message error:", err);
      setError("Erro ao enviar a mensagem.");
    }
  };


  if (loading) return <p className="loading">Loading your messages...</p>;  // loading
  if (error) return <p className="error">{error}</p>;

  return (
    <section className="chat-room-container">
      <aside className="participants-list">
        <h3>Participants</h3>
        {participants.length === 0 ? (
          <p className="no-participants">Nobody.</p>
        ) : (
          <ul>
            {participants.map((p) => (
              <li key={p.id} className="participant-item">
                {p.name}
              </li>
            ))}
          </ul>
        )}
      </aside>

      <div className="chat-area">
        <h2 className="chat-header">{eventName}</h2>

        <div ref={containerRef} className="chat-history">
          {messages.map((msg, i) => {
            // If it’s "User joined the event", only show it if first for that user
            if (msg.message === "User joined the event") {
              const firstIndex = messages.findIndex(
                (m) => Number(m.user_id) === Number(msg.user_id)
              );
              if (firstIndex === i) {
                return (
                  <div key={i} className="system-message">
                    {`${msg.user_name} joined the event`}
                  </div>
                );
              }
              return null;
            }

            // Otherwise, align left/right based on user_id
            const isMe = myId !== null && Number(msg.user_id) === myId;
            return (
              <div
                key={i}
                className={isMe ? "chat-bubble me" : "chat-bubble other"}
              >
                <span className="bubble-sender">{msg.user_name}</span>
                <div className="bubble-text">{msg.message}</div>
              </div>
            );
          })}
        </div>

        <div className="chat-input">
          <input
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={(e) => e.key === "Enter" && sendMessage()}
            placeholder="Type your message..."
          />
          <button className="send-btn" onClick={sendMessage}>
            Enviar
          </button>
        </div>
      </div>
    </section>
  );
};

export default ChatRoom;
