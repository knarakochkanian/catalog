function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

export function errorMessage(error: unknown): string {
  if (isRecord(error)) {
    const body = error["error"];
    const envelope = isRecord(body) ? body["error"] : undefined;
    const message = isRecord(envelope) ? envelope["message"] : undefined;
    if (typeof message === "string" && message.trim()) return message;
    if (error["status"] === 0)
      return "Сервер недоступен. Проверьте соединение и повторите попытку.";
  }
  return "Не удалось выполнить запрос. Повторите попытку.";
}
