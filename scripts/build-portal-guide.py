"""Build the Danish, print-friendly onboarding guide. No external assets or PII."""
from pathlib import Path
from reportlab.pdfgen import canvas
from reportlab.lib.colors import HexColor
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import Paragraph
from reportlab.lib.styles import ParagraphStyle
import os

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'output/pdf/solportalen-installationsguide.pdf'
OUT.parent.mkdir(parents=True, exist_ok=True)
font_dir = Path(os.environ.get('WINDIR', 'C:/Windows')) / 'Fonts'
pdfmetrics.registerFont(TTFont('UI', str(font_dir / 'segoeui.ttf')))
pdfmetrics.registerFont(TTFont('UIBold', str(font_dir / 'segoeuib.ttf')))
pdfmetrics.registerFontFamily('UI', normal='UI', bold='UIBold')
W, H = 595.28, 841.89
BG, INK, MUTED, GREEN = map(HexColor, ['#f5f5ed', '#123b32', '#637267', '#bde97a'])
c = canvas.Canvas(str(OUT), pagesize=(W, H))
c.setTitle('Solportalen - fra Raspberry Pi til dit energioverblik')
c.setAuthor('Solportalen')

def para(text, y, size=11, width=475, color=INK, bold=False, x=60):
    st = ParagraphStyle('p', fontName='UIBold' if bold else 'UI', fontSize=size,
                        leading=size*1.55, textColor=color, spaceAfter=0)
    p = Paragraph(text, st)
    _, height = p.wrap(width, 700)
    p.drawOn(c, x, y-height)
    return y-height-14

def page(n, label):
    c.setFillColor(BG); c.rect(0, 0, W, H, fill=1, stroke=0)
    c.setFillColor(INK); c.setFont('UIBold', 12); c.drawString(60, H-48, 'SOLPORTALEN')
    c.setFillColor(MUTED); c.setFont('UI', 8); c.drawRightString(W-60, H-48, label.upper())
    c.setStrokeColor(HexColor('#d6dfce')); c.line(60, 55, W-60, 55)
    c.setFont('UI', 8); c.drawString(60, 36, 'solpanel.linder.dk  |  Installationsguide  |  September 2026')
    c.drawRightString(W-60, 36, f'{n} / 6')

def title(kicker, text):
    para(kicker, 744, size=9, color=MUTED, bold=True)
    return para(text, 715, size=30, bold=True)

def box(text, y, dark=False):
    st=ParagraphStyle('b',fontName='UI',fontSize=10,leading=15,textColor=GREEN if dark else INK)
    p=Paragraph(text,st); _,h=p.wrap(435,600)
    c.setFillColor(INK if dark else HexColor('#e7eddb')); c.roundRect(60,y-h-30,475,h+30,12,stroke=0,fill=1)
    p.drawOn(c,80,y-h-15)
    return y-h-48

def code(lines,y):
    height=24+len(lines)*16
    c.setFillColor(INK);c.roundRect(60,y-height,475,height,10,fill=1,stroke=0)
    c.setFillColor(GREEN);c.setFont('Courier',8.0)
    for i,line in enumerate(lines):
        if pdfmetrics.stringWidth(line,'Courier',8)>435: raise ValueError('Code line too long: '+line)
        c.drawString(78,y-22-i*16,line)
    return y-height-22

page(1,'Dit hjem. Din energi.')
y=title('FRA SD-KORT TIL SOLOVERBLIK','Forbind dit hjem.<br/>Behold kontrollen.')
y=para('En praktisk guide til Raspberry Pi, lokal solstyring og dit private, mobilvenlige dashboard.',y-12,size=15)
c.setFillColor(INK);c.roundRect(60,290,475,225,24,stroke=0,fill=1)
c.setStrokeColor(HexColor('#4f7462'));c.line(150,400,300,400);c.line(300,400,445,400)
for x,label,sub in [(150,'DIT ANLÆG','Growatt / RS485'),(300,'DIN PI','Lokal styring'),(445,'DIN KONTO','Sikkert overblik')]:
    c.setFillColor(GREEN);c.circle(x,405,26,fill=1,stroke=0)
    c.setFillColor(INK);c.setFont('UIBold',17);c.drawCentredString(x,399,'+' if x==150 else ('P' if x==300 else 'S'))
    c.setFillColor(HexColor('#eff4dc'));c.setFont('UIBold',9);c.drawCentredString(x,350,label)
    c.setFont('UI',8);c.drawCentredString(x,333,sub)
para('Én udgående HTTPS-forbindelse. Ingen portåbning i routeren.',310,size=9,color=GREEN,x=92,width=410)
y=para('Det får du',255,size=15,bold=True)
y=para('Solproduktion, forbrug, net og batteri på mobilen. Historik op til 24 måneder. Tidsbegrænsede modeændringer, hvis du selv giver tilladelse på Pi’en.',y)
para('Nye installationer starter uden Modbus-writes. Din eksisterende, afprøvede installation beholder sine indstillinger.',y,size=10,color=MUTED)
c.showPage()

page(2,'Forberedelse')
y=title('TRIN 01 + 02','Gør klar til forbindelsen.')
for head,text in [
 ('Din konto','Opret en konto på <b>https://solpanel.linder.dk</b>. Bekræft linket i din e-mail, og log ind. Under Mit anlæg opretter du et anlæg med et navn, du kan genkende.'),
 ('Din Raspberry Pi','Brug Raspberry Pi OS / Debian i en opdateret 64-bit version, et stabilt strømforsyningsmodul og et SD-kort eller en SSD. Aktivér SSH og opret din egen bruger, når du installerer operativsystemet.'),
 ('Dit netværk','Forbind Pi’en til hjemmenetværket, gerne med kabel. Find dens IP i routeren. Pi’en skal kunne nå internettet via HTTPS. Du skal ikke viderestille porte eller eksponere det lokale dashboard.'),
 ('Dit solanlæg','Softwaren er udviklet til det afprøvede Growatt SPH-setup. Model, registerprofil og RS485-forbindelse skal passe til dit konkrete anlæg. Andre modeller kræver særskilt validering.')]:
    y=para(head,y,size=14,bold=True);y=para(text,y)
y=code(['ssh DIN_BRUGER@DIN_PI_ADRESSE'],y)
box('Vigtigt: Et RJ45-stik er ikke nødvendigvis Ethernet. Brug kun port og pinout fra manualen til præcis din inverter. Åbn ikke elinstallationer; få faglig hjælp ved tvivl.',y)
c.showPage()

page(3,'Download og installation')
y=title('TRIN 03','Installer uden gætværk.')
y=para('Kør nedenstående i din SSH-session på Pi’en. Pakken hentes over HTTPS. SHA-256-kontrollen hjælper med at opdage en ufuldstændig eller ændret download.',y)
y=code(['mkdir -p ~/solportalen-install','cd ~/solportalen-install','curl -fLO https://solpanel.linder.dk/downloads/solportalen-pi.tar.gz','curl -fLO https://solpanel.linder.dk/downloads/SHA256SUMS','sha256sum --check SHA256SUMS','tar -xzf solportalen-pi.tar.gz','cd solportalen','sudo sh scripts/install-raspberry-pi.sh'],y)
y=box('STOP, hvis sha256sum ikke viser OK. Pak ikke filen ud og kør ikke installationen, før kontrollen er bestået.',y)
y=para('Hvad bliver installeret?',y,size=15,bold=True)
y=para('Apache, PHP, MariaDB og de nødvendige lokale services. Der bruges ikke Composer. Applikationen placeres i /opt/solportalen. Ved opdatering bevares .env og var-mappen med lokale indstillinger og data.',y)
y=para('Allerede installeret?',y,size=15,bold=True)
y=para('Tag en databasebackup og en kopi af .env og var før opdatering. Kør installeren fra den udpakkede kildekodemappe, aldrig fra /opt/solportalen. Installerens lokale tests må ikke konkurrere med en anden manuel Modbus-proces.',y)
para('Du skal ikke opdatere software eller ændre registerprofiler midt i en commissioning-test.',y,size=10,color=MUTED)
c.showPage()

page(4,'Målinger og parring')
y=title('TRIN 04 + 05','Først lokale tal. Så online.')
y=para('Åbn http://DIN_PI_ADRESSE i din browser. Sammenlign solproduktion, batteriprocent og netmåling med inverterens eget display. Et tal på nul og en manglende måling er ikke det samme.',y)
y=code(['systemctl status solportal-device --no-pager','journalctl -u solportal-device -n 30 --no-pager'],y)
y=para('Forbind din konto',y,size=16,bold=True)
y=para('Log ind på portalen, vælg Mit anlæg og tilføj dit anlæg. Parringskoden er gyldig i 15 minutter og kan kun bruges én gang. Start derefter parringen på Pi’en:',y)
y=code(['sudo -u solportal php /opt/solportalen/bin/solportal cloud:pair','sudo systemctl enable --now solportal-cloud'],y)
y=para('Indtast koden, når terminalen spørger. Koden udveksles over HTTPS og erstattes af en enhedsnøgle, som gemmes på Pi’en med begrænsede filrettigheder. Undlad at dele eller sende nøglefilen.',y)
y=box('Succes ser sådan ud: Portalen viser friske målinger og et tidspunkt. Pi’en sender cirka hvert 15. sekund. Historikken begynder ved tilslutning; gammel lokal historik importeres ikke automatisk.',y)
y=para('På mobilen',y,size=15,bold=True)
para('Åbn solpanel.linder.dk i din mobilbrowser og log ind. Layoutet tilpasser sig skærmen. Gem eventuelt siden som bogmærke eller genvej på hjemmeskærmen.',y)
c.showPage()

page(5,'Kontrol og sikkerhed')
y=title('TRIN 06','Vælg dine rettigheder.')
y=para('Parring giver overvågning. Fjernstyring kræver både lokal commissioning, aktiverede Modbus-writes og en særskilt tilladelse på Pi’en:',y)
y=code(['sudo -u solportal php /opt/solportalen/bin/solportal cloud:control enable'],y)
y=para('Vælg mode og varighed i dit private dashboard, og bekræft med din adgangskode. Kommandoen er ikke udført, bare fordi den står i kø. Afvent en verificeret kvittering fra Pi’en.',y)
for head,text in [
 ('Gem sol / Battery First','Prioritér batteriet med solenergi. Netopladning er slået fra.'),
 ('Forsyn huset / Load First','Prioritér eget forbrug uden en tvungen lade- eller afladeperiode.'),
 ('Oplad fra net','Battery First med AC-opladning inden for de lokalt konfigurerede grænser.'),
 ('Aflad til net / Grid First','Kan eksportere batterienergi. Den lokale reserve og effektgrænse respekteres.')]:
    y=para(head,y,size=12,bold=True);y=para(text,y,size=10)
y=box('En fjernmode varer 15-120 minutter, dog højst til lokal midnat. Pi’en sætter plananvendelse på pause og går ved udløb til lokal standardmode. Automatisk planlægning kan derefter fortsætte. Det kræver, at den lokale worker kører.',y)
para('Tilbagekald med cloud:control disable. Afkobl enheden i portalen, og brug cloud:unpair lokalt, hvis forbindelsen skal fjernes helt. Det sletter ikke lokal historik.',y,size=10)
c.showPage()

page(6,'Hjælp og næste skridt')
y=title('NÅR DU ER FORBUNDET','Et overblik, du kan stole på.')
for head,text in [
 ('Der kommer ingen mail','Se spamfilteret. Brug Send nyt bekræftelseslink eller Glemt kode på login-siden. Links udløber og kan ikke genbruges. Brug altid den senest modtagne mail.'),
 ('Portalen viser gamle tal','Kontrollér først det lokale dashboard. Se derefter cloud-agentens log med kommandoen nedenfor. Tjek internet og tidsynkronisering. Gamle målinger må ikke læses som nulforbrug.'),
 ('Mode står i kø eller fejler','Kontrollér, at Pi’en er online, og at du lokalt har givet tilladelse. Kvitteringen viser, om kommandoen blev verificeret. Lav ikke gentagne writes eller parallelle tests på serieporten.'),
 ('Hvor er mine offentlige tal?','Deling er frivillig og som udgangspunkt slået fra. Fællesgrafen kræver mindst fem delende anlæg pr. halvtimesinterval. Kun solproduktion summeres, med mindst 30 minutters forsinkelse. Dit forbrug, navn og batteri vises ikke.'),
 ('Hvad sker der ved internetudfald?','Den lokale styring fortsætter. Portalen viser alderen på seneste måling. En ny fjernkommando udløber hurtigt, hvis den ikke kan leveres. Der er ingen automatisk historik-backfill i denne version.')]:
    y=para(head,y,size=12,bold=True);y=para(text,y,size=10)
y=code(['journalctl -u solportal-cloud -n 30 --no-pager'],y)
box('Din næste kontrol: Kan du se friske tal på mobilen? Er netretningen korrekt? Er historikken begyndt? Er standardmode og batterireserve rigtige for dit anlæg?',y)
c.showPage();c.save()
print(OUT)
