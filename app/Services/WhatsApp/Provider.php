<?php

namespace App\Services\WhatsApp;

/**
 * Porta do WhatsApp (CRM, 12/11/2026): a mesma para a API não oficial (Evolution) e para a oficial (Meta, depois).
 * Os números vão só com dígitos e o DDI 55 ("5588994669561").
 */
interface Provider
{
    /** Cria a conexão no servidor (com o webhook que avisa as mensagens). */
    public function setUp(string $webhookUrl, string $webhookSecret): void;

    /** QR Code para conectar (imagem em base64) ou null se já está conectado. */
    public function qrCode(): ?string;

    /** `connected`, `connecting` ou `disconnected`. */
    public function status(): string;

    public function disconnect(): void;

    /** Apaga a conexão no servidor (ao remover o número). */
    public function remove(): void;

    /** @return string|null id da mensagem no WhatsApp */
    public function sendText(string $number, string $text): ?string;

    /** Áudio de voz (o arquivo em base64). @return string|null id da mensagem */
    public function sendAudio(string $number, string $base64): ?string;
}
