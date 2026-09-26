//! stdout belongs exclusively to the framed IPC protocol. Diagnostic output uses stderr.
mod block;
mod math;
mod network;
mod player;
mod server;
mod world;

fn main() {
    if std::env::args().any(|a| a == "--version") {
        println!("MPE-Core {}", env!("CARGO_PKG_VERSION"));
        return;
    }
    if !std::env::args().any(|a| a == "--stdio") {
        eprintln!("Launch ./start.sh; the Rust engine expects a supervised --stdio gateway.");
        std::process::exit(2);
    }
    if let Err(error) = server::Server::new().and_then(|server| server.run()) {
        eprintln!("MPE-Core fatal: {error}");
        std::process::exit(1);
    }
}
