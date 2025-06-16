import React, { useState } from "react";
import { useNavigate } from "react-router-dom";
import { type Event } from "../api/event";
import { useMyEvents } from "../hooks/useMyEvents";
import "./ChatList.css";

const PER_PAGE = 5;  // numero de chats por pagina

const ChatList: React.FC = () => {

  // -----------estados -------------------------
  // usa react-query para fetch, cache, loading e erro
  const {
    data: events = [],
    isLoading,
    isError,
    error,
  } = useMyEvents();

  const [pageA, setPageA]     = useState(1);   // pag atual ativos
  const [pageC, setPageC]     = useState(1);   // pag atual arquivados

  const nav = useNavigate();

  const openChat = (id: number) => nav(`/chat/${id}`);   // redireciona para o chat do evento

  if (isLoading) return <p className="loading">A carregar…</p>;  // loading
  if (isError)   return <p className="error">{(error as Error).message}</p>;

  // separa os eventos em ativos e arquivados
  const active   = events.filter(e => e.status === "in progress");
  const archived = events.filter(e => e.status === "concluded");

  // calcula as fatias para a paginaçao
  const totalA = active.length;    // total de eventos ativos
  const totalC = archived.length;  // total de eventos arquivados
  const pagesA = Math.ceil(totalA / PER_PAGE);   // numero total de paginas de ativos
  const pagesC = Math.ceil(totalC / PER_PAGE);   // numero total de paginas de arquivados
  const sliceA = active.slice((pageA - 1) * PER_PAGE, pageA * PER_PAGE);   // extrai os eventos ativos da pagina atual
  const sliceC = archived.slice((pageC - 1) * PER_PAGE, pageC * PER_PAGE); // extrai os eventos arquivados da pagina atual

  // ---------------componente reutilizavel de paginaçao--------
  const Pager = ({
    page,
    pages,
    onPrev,
    onNext,
  }: {
    page: number;
    pages: number;
    onPrev: () => void;
    onNext: () => void;
  }) => (
    <div className="pager">
      <button onClick={onPrev} disabled={page <= 1}>‹ Prev</button>
      <span>{page} / {pages}</span>
      <button onClick={onNext} disabled={page >= pages}>Next ›</button>
    </div>
  );

  //-------------------------
  return (
    <section className="chat-list-page">
      <h2>Chat Management</h2>
      <div className="chat-columns">

        {/* ─────────────────── ACTIVE ─────────────────── */}
        <div className="column">
          <h3>Active Activities</h3>
          {totalA === 0 && <p>No active chats.</p>}
          {sliceA.map((ev) => (
            <div
              key={ev.id}
              className="chat-card"
              onClick={() => openChat(ev.id)}
            >
              <span className="icon">💬</span>
              <div className="info">
                <strong className="title">{ev.name}</strong>
                <span className="sport">{ev.sport ?? "—"}</span>
              </div>
              <button
                className="see-btn"
                onClick={(e) => { e.stopPropagation(); openChat(ev.id); }}
              >
                See Chat ›
              </button>
            </div>
          ))}
          {pagesA > 1 && (
            <Pager
              page={pageA}
              pages={pagesA}
              onPrev={() => setPageA((p) => Math.max(1, p - 1))}
              onNext={() => setPageA((p) => Math.min(pagesA, p + 1))}
            />
          )}
        </div>

        {/* ────────────────── ARCHIVED ────────────────── */}
        <div className="column">
          <h3>Concluded Activities</h3>
          {totalC === 0 && <p>No archived chats.</p>}
          {sliceC.map((ev) => (
            <div
              key={ev.id}
              className="chat-card archived"
              onClick={() => openChat(ev.id)}
            >
              <span className="icon">💬</span>
              <div className="info">
                <strong className="title">{ev.name}</strong>
                <span className="sport">{ev.sport ?? "—"}</span>
              </div>
              <button
                className="see-btn"
                onClick={(e) => { e.stopPropagation(); openChat(ev.id); }}
              >
                See Chat ›
              </button>
            </div>
          ))}
          {pagesC > 1 && (
            <Pager
              page={pageC}
              pages={pagesC}
              onPrev={() => setPageC((p) => Math.max(1, p - 1))}
              onNext={() => setPageC((p) => Math.min(pagesC, p + 1))}
            />
          )}
        </div>

      </div>
    </section>
  );
};

export default ChatList;
